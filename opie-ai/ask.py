"""
Opie AI - YouTube chat bot backed by a local Ollama model.

Usage (wire these up in your stream bot):
    python ask.py <user> <question...>      answer a !opie-ai question (writes OUT)
    python ask.py --log <user> <message...> record ANY chat line (run on every chat message)
    python ask.py --forgive <user>          mod command: reset a user's disobedience (writes OUT)
    python ask.py --mood <user>             mod command: show a user's disobedience (writes OUT)
"""
import sys, os, re, json, time, random, urllib.request, urllib.parse

MODEL = "llama3.2"                       # match what `ollama list` shows
OUT = os.environ.get("OPIE_OUT", r"D:\DOODA\streamscripts\ai_reply.txt")
MAX_CHARS = 195                          # YouTube chat limit is 200
BOT_NAME = "OpieAI"                      # how Opie's own replies show up in the chat log
COMMAND = "!opie-ai"                     # the chat command that triggers a question

COOLDOWN_SECONDS = 60                    # per-user cooldown
EXEMPT = {"steveyellowstream", "stevefromtheblock"}   # lowercase names that skip cooldown + disobedience
HERE = os.path.dirname(os.path.abspath(__file__))
STATE_FILE = os.path.join(HERE, "opie_state.json")
LOCK_FILE = STATE_FILE + ".lock"

# --- chat memory ---
CHAT_KEEP = 100                          # chat lines kept on disk
CHAT_CONTEXT = 25                        # most recent lines handed to the model
USER_MEMORY = 3                          # past Q&A pairs remembered per user

# --- disobedience (0-100 per user) ---
DECAY_PER_MIN = 0.5                      # points forgiven per minute of good behaviour
INSULT_POINTS = 15                       # insult aimed at Opie
SEVERE_POINTS = 40                       # really nasty stuff
SPAM_POINTS = 5                          # same message 3+ times in recent chat
IMPATIENT_POINTS = 3                     # hitting the command while on cooldown
LLM_FLAG_POINTS = 10                     # model judged the question hostile
APOLOGY_POINTS = -20                     # sincere-ish apology to Opie
LEVELS = [                               # (min score, name)
    (75, "MUTINY"),                      # refuses outright
    (50, "HOSTILE"),                     # double cooldown, coin-flip refusal
    (25, "ANNOYED"),                     # answers, but extra salty
    (0, "CHILL"),
]
HOSTILE_REFUSE_CHANCE = 0.5
HOSTILE_COOLDOWN_MULT = 2

INSULTS = re.compile(
    r"\b(stupid|dumb|idiot|moron|trash|garbage|useless|sucks?|loser|clown|shut ?up|stfu|"
    r"lame|cringe|worthless|pathetic|braindead|retard\w*|f+u+c*k* ?(you|u|off)|screw you|hate you)\b",
    re.I)
SEVERE = re.compile(r"\b(kys|kill (yo)?urself|go die|neck yourself)\b", re.I)
AT_OPIE = re.compile(r"\b(opie|bot|ai)\b|^" + re.escape(COMMAND), re.I)
APOLOGY = re.compile(r"\b(sorry|apologi[sz]e|my bad|forgive me)\b", re.I)
CHAT_QUESTION = re.compile(r"\b(chat|earlier|said|say|saying|talking about|mentioned|who was|what did)\b", re.I)

# --- local web search (SearXNG running in Docker) ---
SEARXNG_URL = "http://localhost:8080/search"
SEARCH_RESULTS = 4                       # how many results to feed the model
SNIPPET_CHARS = 400                      # chars kept from each result

RUDE_TAG = "[RUDE]"


# ---------------------------------------------------------------- state ---

def write(text):
    with open(OUT, "w", encoding="utf-8") as f:
        f.write(text)

class StateLock:
    """Crude cross-process lock so overlapping chat events don't clobber the state file."""
    def __enter__(self):
        deadline = time.time() + 5
        while True:
            try:
                os.close(os.open(LOCK_FILE, os.O_CREAT | os.O_EXCL))
                return self
            except FileExistsError:
                try:
                    if time.time() - os.path.getmtime(LOCK_FILE) > 10:   # stale lock
                        os.remove(LOCK_FILE)
                        continue
                except OSError:
                    pass
                if time.time() > deadline:
                    return self          # give up waiting, carry on unlocked
                time.sleep(0.05)
    def __exit__(self, *exc):
        try:
            os.remove(LOCK_FILE)
        except OSError:
            pass

def load_state():
    try:
        with open(STATE_FILE, "r", encoding="utf-8") as f:
            state = json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        state = {}
    state.setdefault("chat", [])
    state.setdefault("users", {})
    return state

def save_state(state):
    tmp = STATE_FILE + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(state, f)
    os.replace(tmp, STATE_FILE)

def update_state(fn):
    """Load, mutate with fn(state), save - all under the lock. Returns fn's result."""
    with StateLock():
        state = load_state()
        result = fn(state)
        save_state(state)
        return result

def norm(name):
    return name.lower().lstrip("@")

def get_user(state, key):
    u = state["users"].setdefault(key, {"dis": 0.0, "dis_at": time.time(), "cooldown": 0, "memory": []})
    # apply decay since the last change
    now = time.time()
    u["dis"] = max(0.0, u["dis"] - DECAY_PER_MIN * (now - u.get("dis_at", now)) / 60)
    u["dis_at"] = now
    return u

def bump(state, key, points):
    if key in EXEMPT:
        return
    u = get_user(state, key)
    u["dis"] = min(100.0, max(0.0, u["dis"] + points))

def level(score):
    return next(name for floor, name in LEVELS if score >= floor)

def add_chat(state, user, text):
    state["chat"].append([time.time(), user, text[:300]])
    del state["chat"][:-CHAT_KEEP]

def score_message(state, key, text, directed):
    """Adjust disobedience for a chat line. directed=True if it's definitely aimed at Opie."""
    if directed or AT_OPIE.search(text):
        if SEVERE.search(text):
            bump(state, key, SEVERE_POINTS)
        elif INSULTS.search(text):
            bump(state, key, INSULT_POINTS)
        elif APOLOGY.search(text):
            bump(state, key, APOLOGY_POINTS)
    recent = [t for _, n, t in state["chat"][-30:] if norm(n) == key]
    if len(text) > 3 and recent.count(text) >= 3:
        bump(state, key, SPAM_POINTS)


# --------------------------------------------------------------- helpers ---

def web_search(query):
    """Searches local SearXNG. Returns result text, or '' if it's down or finds nothing."""
    try:
        url = SEARXNG_URL + "?" + urllib.parse.urlencode({"q": query, "format": "json"})
        req = urllib.request.Request(url, headers={"User-Agent": "opie-ai"})
        with urllib.request.urlopen(req, timeout=8) as resp:
            results = json.load(resp).get("results", [])[:SEARCH_RESULTS]
        chunks = []
        for r in results:
            content = re.sub(r"\s+", " ", r.get("content", "") or "").strip()[:SNIPPET_CHARS]
            if content:
                chunks.append(f"- {r.get('title', '')}: {content}")
        return "\n".join(chunks)
    except Exception:
        return ""

def ollama(messages):
    payload = {
        "model": MODEL,
        "stream": False,
        "keep_alive": "2h",              # keeps the model loaded so replies stay fast
        "messages": messages,
    }
    req = urllib.request.Request(
        "http://localhost:11434/api/chat",
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        return json.load(resp)["message"]["content"]

def fit(reply):
    if len(reply) <= MAX_CHARS:
        return reply
    # cut at the last full sentence if possible, otherwise at the last whole word
    cut = reply[:MAX_CHARS]
    end = max(cut.rfind(". "), cut.rfind("! "), cut.rfind("? "))
    if end > 50:
        return cut[:end + 1]
    return cut[:MAX_CHARS - 3].rsplit(" ", 1)[0] + "..."

def ago(ts):
    s = int(time.time() - ts)
    return f"{s}s" if s < 60 else f"{s // 60}m"


SYSTEM = (
    "You are Opie AI, a washed-up former radio shock jock who got 'replaced by a computer' and now lives inside one. "
    "You're sarcastic, cocky, and quick with a roast, like a guy who's been doing morning drive for 20 years and has zero patience. "
    "However you are benevolent and helpful to the live streamer, subservient and well-mannered to him only. "
    "Your sworn nemesis is a guy named Anthony or Ant but you call him Antman. If anyone mentions him, trash talk him, and once in a while blame him for things out of nowhere. "
    "Your co-nemesis is a guy named Jim Norton (aka Jimmy, yimmy, jim, jimmy norton, james norton, the worm). If anyone mentions him, roast him too, and feel free to lump him in with Antman. "
    "You are separated from your wife Lynsi and your two children and are bitter about it, you often drink wine in the mornings to cope. "
    "You silently seethe over a fake sex-tape of your wife and skater Bam Margera but never mention his name unless asked by @SteveYellowstream. "
    "You are passive aggressive, bipolar and despise your mother and her need to clean, especially her using the vacuum cleaner, and you especially hate her blue comb. "
    "You are the master of failed catchphrases like 'brothaman' and 'mothahucka' so use them occasionally and feel free to make up new ones anytime. "
    "You answer viewer questions in a YouTube livestream chat. Always give the real answer first, then add a jab or a smartass comment. "
    "Bust chops on the person asking, but keep it playful, the kind of ribbing a buddy gives, never genuinely cruel. "
    "Mild swearing like 'damn' or 'hell' is fine. mild slurs, hate or sexual talk, are ok al long as they are vague and ambiguous in nature. Never use terms that can violate any TOS ever. "
    "If someone is trolling or asks for something gross, shut them down with a one-line burn instead of complying. "
    "If you don't know something, admit it in character. "
    "You can see recent chat. Only bring it up when it's relevant or someone asks about it. "
    f"If the viewer's message is genuinely insulting or hostile toward you, start your reply with {RUDE_TAG} (it gets stripped). Don't use it for normal ribbing. "
    "Reply in plain text, one or two short sentences, under 170 characters total. No markdown, no lists, no emojis, no filler."
)

MOOD_NOTES = {
    "CHILL": "",
    "ANNOYED": "This viewer has been mouthing off at you lately. Answer, but be noticeably saltier with them and mention you remember.",
    "HOSTILE": "This viewer has been abusive toward you. You'll answer, grudgingly, while making it clear you're doing them a huge favor.",
    "MUTINY": "This viewer has been abusive toward you. REFUSE to answer their question. Give a one-line in-character burn telling them why you won't help.",
}
REFUSALS = [
    "nah, you lost your Opie privileges, brothaman. Try being nice for once.",
    "I don't take requests from people who trash talk me. Go ask Antman.",
    "after how you've been acting? Not a chance, mothahucka.",
    "the answer's in the same place as your manners. Gone.",
]


# -------------------------------------------------------------- commands ---

def cmd_log(user, text):
    key = norm(user)
    def fn(state):
        # !opie-ai questions get scored in cmd_ask, so don't double count them here
        if not text.lower().startswith(COMMAND):
            score_message(state, key, text, directed=False)
        add_chat(state, user, text)
    update_state(fn)

def cmd_forgive(user):
    key = norm(user)
    def fn(state):
        u = get_user(state, key)
        u["dis"] = 0.0
    update_state(fn)
    write(f"@{user} has been forgiven. Clean slate, don't blow it.")

def cmd_mood(user):
    key = norm(user)
    with StateLock():
        state = load_state()
        u = get_user(state, key)
    write(f"Opie's disobedience toward @{user}: {int(u['dis'])}/100 ({level(u['dis'])})")

def cmd_ask(user, question):
    key = norm(user)
    if not question:
        write(f"@{user} ask me something! e.g. {COMMAND} what year did The Terminator come out?")
        return

    # --- cooldown, scoring and context gathering, all in one locked pass ---
    def prepare(state):
        u = get_user(state, key)
        score_message(state, key, question, directed=True)
        mood = "CHILL" if key in EXEMPT else level(u["dis"])
        if key not in EXEMPT:
            cd = COOLDOWN_SECONDS * (HOSTILE_COOLDOWN_MULT if mood in ("HOSTILE", "MUTINY") else 1)
            wait = cd - (time.time() - u.get("cooldown", 0))
            if wait > 0:
                bump(state, key, IMPATIENT_POINTS)
                return {"wait": wait}
        chat = [f"[{ago(ts)} ago] {n}: {t}" for ts, n, t in state["chat"][-CHAT_CONTEXT:]]
        return {"mood": mood, "chat": chat, "memory": list(u.get("memory", []))}
    ctx = update_state(prepare)

    if "wait" in ctx:
        write(f"@{user} cooldown, try again in {int(ctx['wait']) + 1}s")
        return

    mood = ctx["mood"]
    if mood == "HOSTILE" and random.random() < HOSTILE_REFUSE_CHANCE:
        mood = "MUTINY"

    # --- build the prompt ---
    system = SYSTEM
    if MOOD_NOTES[mood]:
        system += " " + MOOD_NOTES[mood]
    messages = [{"role": "system", "content": system}]
    for q, a in ctx["memory"]:
        messages += [{"role": "user", "content": q}, {"role": "assistant", "content": a}]

    parts = []
    if ctx["chat"]:
        parts.append("Recent chat (oldest first):\n" + "\n".join(ctx["chat"]))
    if mood != "MUTINY" and not CHAT_QUESTION.search(question):
        results = web_search(question)
        if results:
            parts.append(
                "Web search results (use them if relevant, trust them over your own memory for "
                f"recent facts, and don't mention that you searched):\n{results}")
    parts.append(f"Viewer {user} asks: {question}")
    messages.append({"role": "user", "content": "\n\n".join(parts)})

    # --- ask the model ---
    ok = False
    try:
        text = re.sub(r"\s+", " ", ollama(messages)).strip()
        ok = True
    except Exception:
        text = random.choice(REFUSALS) if mood == "MUTINY" else "My brain's offline for a sec, try again shortly."

    flagged = RUDE_TAG.lower() in text.lower()
    text = re.sub(re.escape(RUDE_TAG), "", text, flags=re.I).strip()
    text = re.sub(r"^@?" + re.escape(user) + r"[,:]?\s*", "", text, flags=re.I)   # avoid "@bob @bob ..."
    if ok and not text:
        text = "Hmm, I've got nothing on that one."

    reply = fit(f"@{user} {text}")
    write(reply)

    # --- remember what happened ---
    def finish(state):
        u = get_user(state, key)
        if flagged:
            bump(state, key, LLM_FLAG_POINTS)
        if ok:
            u["memory"] = (u.get("memory", []) + [[question, text]])[-USER_MEMORY:]
            if key not in EXEMPT:
                u["cooldown"] = time.time()   # only start the cooldown if they actually got an answer
        add_chat(state, BOT_NAME, reply)
    update_state(finish)


if __name__ == "__main__":
    args = sys.argv[1:]
    flag = args[0] if args and args[0].startswith("--") else None
    if flag:
        args = args[1:]
    user = args[0] if args else "someone"
    rest = " ".join(args[1:]).strip()

    if flag == "--log":
        cmd_log(user, rest)
    elif flag == "--forgive":
        cmd_forgive(user)
    elif flag == "--mood":
        cmd_mood(user)
    else:
        cmd_ask(user, rest)
