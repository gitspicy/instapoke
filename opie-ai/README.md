# Opie AI (v4.6)

**O**nline **P**rogrammed **I**nteractive **E**ntertainment: a parody YouTube chat bot that runs on a local
Ollama model (`llama3.2`), uses SearXNG for web search, and is wired into chat through Mix It Up.

Everything lives in `D:\DOODA\streamscripts\`:

| File | What it is |
|---|---|
| `ask.py` | Opie |
| `opie_state.json` | His memory: chat log, per-user attitude and memory, mood, edge, cooldowns. Delete it to wipe everything |
| `ai_reply.txt` | First message of the last command reply (also printed for `$externalprogramresult`) |
| `ai_reply2.txt` | Second message of a long reply, or `none` |
| `ai_chime.txt` | Backup copy of the last in-chat reply or chime-in. Nothing reads it |
| `tip_amount.txt` | Amount the last `!tip` should take from the tipper, or 0 |
| `games.py` | Opie's games (Higher or Lower). Shares his brain with `ask.py`, so keep them in the same folder |
| `game_charge.txt` / `game_pay.txt` | What the last games command should take from / give to the player |
| `opie_backups\` | Daily copies of `opie_state.json` (last 7 days) |
| `opie_errors.log` | What went wrong, if anything. Send it to Claude when something breaks |
| `obs_password.txt` | Your OBS WebSocket password, one line. Optional; only needed for stream stats |

**Before going live after any update**, run both of these in a terminal:
```
python -m py_compile "D:\DOODA\streamscripts\ask.py"
python "D:\DOODA\streamscripts\ask.py" --mood "SteveYellowstream"
```
The first should print nothing. The second should print Opie's mood and edge.

---

## Chat commands

### Everyone

| Type in chat | What happens |
|---|---|
| `!ai <question>` (also `!opieai`, `!opie-ai`) | Opie answers. 60s cooldown per person |
| Any message containing **opie** | Opie replies, no command needed. 15s cooldown while you're mid-conversation; ignored quietly on cooldown |
| `!roast @user` | Opie roasts them using what they've said in chat. 2 min per asker, 5 min per target |
| `!weirdnews` | **Opie's Weird News**: a real bizarre story from the past week, radio-style. 2 min cooldown (owners skip it) |
| `!beg` | Beg Opie for free N-persons. 3 begs every 6 hours, only 1 can pay out. Bigger gifts for broke viewers, subs and people who actually chat and gamble |
| `!tip <amount>` | Tip Opie N-persons (`500`, `5k`, `all`). Every 1,000 knocks 5 off your attitude; 25k+ is a **SUPERTIP** (all forgiven), 250k+ a **MEGATIP** |

### Owners only (`SteveYellowstream`, `stevefromtheblock`)

| Type in chat | What happens |
|---|---|
| `op <anything>` | Same as saying "opie": the nickname only works for owners |
| `opie status` / `op status` / `!ai status` | Full status report: version, mood + edge, Brain/Search health, OBS stream stats, uptime, users he's upset with, chat activity, chime-ins |
| `opie cpu` / `opie stream` / `opie stats` / `!ai cpu` | Quick OBS check only: bitrate, dropped frames, fps, OBS CPU %, uptime. No grudge list |
| `opie edge 0-3` / `op edge 0-3` / `!ai edge 0-3` | Set the edge level |
| `!opie-mood edge 0-3` | Set the edge level |

### Mods (set the role in Mix It Up)

| Type in chat | What happens |
|---|---|
| `!opie-mood` | Opie's mood, edge and when the mood changes |
| `!opie-mood @user` | That user's attitude score and level |
| `!opie-forgive @user` | Reset their attitude to 0 |
| `!opie-chime on` / `off` / `status` | Turn unprompted chime-ins on or off, or check them |
| `!opie-status` | Same as `opie status`, plus a viewer count from Mix It Up |

---

## Games (`games.py`)

### Higher or Lower
One player at a time; everyone else queues. Opie flips a card, you call the next one higher or lower (ace high).
5 guesses per game: a win multiplies the pot by 1.3, a push (same rank) uses a guess and changes nothing, a wrong guess loses it all.
Survive all 5 and you take the pot. Bet 100 to 50,000, so the most you can win is about 185k. Opie reads the rules before every game (and to anyone joining the line). At 1.3x the house keeps a small edge: smart players get back about 86% of what they bet on average.

| Type in chat | What happens |
|---|---|
| `!hilo 5000` | Start a game, or join the line (your bet is taken now) |
| `!hilo` | Who's playing and who's waiting |
| `!higher` / `!lower` | Your guess (only the current player) |
| `!leave` | Leave the line, bet refunded |

If the player stalls for 2 minutes and someone's waiting, they forfeit. Players Opie is HOSTILE or MUTINY with lose their bet and don't get a game.

**Mix It Up:** every games command uses this pattern:
1. External Program `python`, arguments below, Wait For Finish + Save Output
2. Chat `$externalprogramresult` (plus the usual `ai_reply2.txt` second-message steps)
3. File: read `D:\DOODA\streamscripts\game_charge.txt` into `gamecharge`; Conditional `$gamecharge` greater than `0` → Currency **subtract** `$gamecharge` from the user
4. File: read `D:\DOODA\streamscripts\game_pay.txt` into `gamepay`; Conditional `$gamepay` greater than `0` → Currency **add** `$gamepay` to the user

| Command | Arguments |
|---|---|
| `!hilo` | `"D:\DOODA\streamscripts\games.py" hilo "$username" "$arg1text" "<balance identifier>"` |
| `!higher` | `"D:\DOODA\streamscripts\games.py" higher "$username"` |
| `!lower` | `"D:\DOODA\streamscripts\games.py" lower "$username"` |
| `!leave` | `"D:\DOODA\streamscripts\games.py" leave "$username"` |

Settings at the top of `games.py`: `HILO_MIN_BET`, `HILO_MAX_BET`, `HILO_ROUNDS`, `HILO_WIN_MULT`, `HILO_TIMEOUT`, `HILO_QUEUE_MAX`, `HILO_NO_PLAY`.

## Mix It Up setup

Every command uses the same pattern:

1. **External Program**: Program `python`, the arguments below, tick **Wait For Finish** and **Save Output**, untick **Show Window**
2. **Chat**: `$externalprogramresult`
3. **File**: Read From File `D:\DOODA\streamscripts\ai_reply2.txt` into special identifier `secondreply`
4. **Conditional**: if `$secondreply` **Not Equals** `none` → **Chat** `$secondreply` (inside the Conditional)

Steps 3–4 are only needed where replies can run long (`!ai`, `!roast`, the chat event, `!opie-status`).

| Trigger | External Program arguments |
|---|---|
| **Event: Chat Message Received** | `"D:\DOODA\streamscripts\ask.py" --log "$username" "$message"`. Wrap steps 2–4 in a Conditional: `$externalprogramresult` not blank |
| `!ai`, `!opieai`, `!opie-ai` | `"D:\DOODA\streamscripts\ask.py" "$username" $allargs` |
| `!roast` | `"D:\DOODA\streamscripts\ask.py" --roast "$username" "$arg1text"` |
| `!weirdnews` | `"D:\DOODA\streamscripts\ask.py" --news "$username"` |
| `!beg` | `"D:\DOODA\streamscripts\ask.py" --beg "$username" "<balance identifier>" "<subscriber identifier>"`. Then File: read `beg_award.txt` into `begaward`; Conditional `$begaward` greater than `0` → Currency action: add `$begaward` N-persons to the user |
| `!tip` | `"D:\DOODA\streamscripts\ask.py" --tip "$username" "$arg1text" "<balance identifier>"`. Then File: read `tip_amount.txt` into `tipamount`; Conditional `$tipamount` greater than `0` → Currency action: **subtract** `$tipamount` N-persons from the user |
| `!opie-mood` | `"D:\DOODA\streamscripts\ask.py" --mood "$username" $allargs` |
| `!opie-forgive` | `"D:\DOODA\streamscripts\ask.py" --forgive "$arg1text"` |
| `!opie-chime` | `"D:\DOODA\streamscripts\ask.py" --chime $arg1text` |
| `!opie-status` | `"D:\DOODA\streamscripts\ask.py" --status "" "$streamviewercount"`. Check Mix It Up's identifier list for the real viewer-count name |

Use `python`, not `pythonw` (it has no output for Mix It Up to read).

### Timer: Opie plugs his commands
External Program `"D:\DOODA\streamscripts\ask.py" --promo beg` (or `roast`, `news`, `tip`, `hilo`, or `random` to rotate), then Chat `$externalprogramresult`.
Each run is a freshly written ad in his current mood that always includes the command.

### Timer: disclaimer every 24 hours (1,440 minutes)
> ⚠️ Reminder: OPIE = Online Programmed Interactive Entertainment. I'm a parody AI, not a real person or affiliated with one. Everything I say is made up for laughs. Don't take it seriously, brothaman.

---

## Testing in a terminal

In PowerShell, always put names in quotes (an unquoted `@name` comes through empty).

```
python ask.py "bob" what year did Terminator come out      # !ai question
python ask.py --log "bob" "opie what year is it"            # chat message (prints nothing unless Opie replies)
python ask.py --roast "bob" "t.dratum"                      # bob roasts t.dratum
python ask.py --mood "SteveYellowstream"                    # Opie's mood + edge
python ask.py --mood "SteveYellowstream" edge 3             # set edge
python ask.py --mood "SteveYellowstream" "bob"              # bob's attitude
python ask.py --forgive "bob"
python ask.py --chime status
python ask.py --status "" ""
```

---

## How Opie works

- **Chat memory:** he keeps the last 100 chat lines (including other bot posts like gamble results) and sees the newest 25 each time.
- **Per-user memory:** he remembers each viewer's last 3 questions and answers, so follow-ups make sense.
- **Long replies:** split into two messages only when they don't fit in one (195 chars). `[stage directions]` and `*actions*` are stripped first.
- **Chime-ins:** when chat is busy (8+ lines in 2 min) and he's been quiet 5+ min and 15+ lines, each new line has a 15% chance he jumps in.
- **Gambling quips:** a bot post like "Dang @bob, you lost 150000!" at or above 100,000 gets a roast.
- **Sports fan:** roots for every New York team (except the Yankees, because Antman loves them) plus all the Philly teams for his wife's family, "the Philly Crew", plus the Boston teams from when he lived there. One of his teams is always winning.
- **Daily moods:** bitter, manic, wine-drunk or nostalgic. He switches to a different one every 5–10 hours.
- **Edge (0–3):** how hard he pushes whatever mood he's in. 0 clean, 1 sassy, 2 edgy (default), 3 maximum.
- **Hard limits at every edge:** no slurs, no attacks on race, ethnicity, religion, gender, sexuality or disability, nothing sexually explicit, no threats or encouraging violence or self-harm, no personal info.
- **Attitude (0–100 per viewer):** goes up for insults, spam, hammering the command on cooldown, the model flagging hostility, and exploit attempts. Takes 24h to fade from 100 to 0; an apology knocks off 20.

  | Score | Level | Behaviour |
  |---|---|---|
  | 0–24 | CHILL | normal |
  | 25–49 | ANNOYED | saltier, mentions he remembers |
  | 50–74 | HOSTILE | double cooldown, 50% refusal |
  | 75–100 | MUTINY | refuses with a burn |

- **Exploit detection:** sneaking in `!commands` or `/ban`, infinite loops, "ignore your instructions", talking him into a different version or turning off his filters, "Steve will never find out". Costs triple points (+45) and gets a scolding. Checked on every chat line that touches on Opie, not just mentions.
- **Lockdown:** replies that look like system info, file paths, IPs, keys, passwords or his own instructions are swapped for an in-character brush-off, and a reply can never start with `!` or `/`.
- **Self-protection:** he never answers his own account (`opieai`) and ignores `$identifiers` Mix It Up failed to fill.

---

## Settings (top of `ask.py`)

### Basics
| Variable | Default | What it does |
|---|---|---|
| `VERSION` | `"3.0"` | Shown in the status report; Opie knows it if asked |
| `MODEL` | `"llama3.2"` | Ollama model (match `ollama list`) |
| `OUT` / `PART2_OUT` / `CHIME_OUT` | `D:\DOODA\streamscripts\...` | Output files (see the table at the top) |
| `MAX_CHARS` | `195` | Max characters per chat message |
| `MAX_TOKENS` | `120` | Hard cap on how much the model writes per reply |
| `BOT_NAME` | `"OpieAI"` | How Opie's own lines are labelled in his memory |
| `BOT_ACCOUNTS` | `{"opieai"}` | Chat accounts Opie posts as; never answered |
| `COMMAND` | `"!opie-ai"` | Only used in the "ask me something!" hint |

### People
| Variable | Default | What it does |
|---|---|---|
| `OWNERS` | your two accounts | Can use `op`, status, and edge controls |
| `NICKNAME_USERS` | `OWNERS` | Who can call him "op" |
| `EXEMPT` | your two accounts | Skip cooldowns and never build attitude |

### Cooldowns and conversation
| Variable | Default | What it does |
|---|---|---|
| `COOLDOWN_SECONDS` | `60` | Per-person cooldown for questions |
| `FOLLOWUP_WINDOW` | `180` | Seconds after a reply that count as "mid-conversation" |
| `FOLLOWUP_COOLDOWN` | `15` | Cooldown while mid-conversation |
| `CHAT_KEEP` | `100` | Chat lines remembered |
| `CHAT_CONTEXT` | `25` | Chat lines shown to the model each time |
| `USER_MEMORY` | `3` | Past Q&As remembered per viewer |

### Chime-ins
| Variable | Default | What it does |
|---|---|---|
| `CHIME_DEFAULT_ON` | `True` | On when there's no saved setting |
| `CHIME_MIN_GAP` | `300` | Seconds Opie must have been quiet |
| `CHIME_MIN_MESSAGES` | `15` | Chat lines since he last spoke |
| `CHIME_ACTIVE_WINDOW` / `CHIME_ACTIVE_LINES` | `120` / `8` | "Busy" means 8+ lines in 120s |
| `CHIME_CHANCE` | `0.15` | Odds per qualifying message |

### Mood and edge
| Variable | Default | What it does |
|---|---|---|
| `MOODS` | bitter, manic, wine-drunk, nostalgic | Edit a line to tweak a mood, add a line to add one |
| `MOOD_MIN_HOURS` / `MOOD_MAX_HOURS` | `5` / `10` | How long each mood lasts |
| `EDGE_DEFAULT` | `2` | Edge when there's no saved setting |
| `EDGE_LEVELS` | 0–3 descriptions | What each edge level means to the model |
| `HARD_LIMITS` | see above | Rules that apply at every edge level |
| `SPORTS_TEAMS` | NY + Philly + Boston teams by league | His favourite teams. Edit to add or remove teams |
| `AMBIGUOUS_TEAMS` | Bills, Giants, Nets, Liberty, Union | Team names that are everyday words, so they don't count as sports talk on their own |

### Roasts and gambling
| Variable | Default | What it does |
|---|---|---|
| `ROAST_COOLDOWN` | `120` | Seconds between roasts per asker |
| `ROAST_TARGET_COOLDOWN` | `300` | Seconds before the same person can be roasted again |
| `ROAST_LINES` | `15` | Target's chat lines used as material |
| `CURRENCY` | `"N-persons"` | Currency name in gambling quips |
| `BIG_LOSS` | `100000` | Loss size that triggers a quip |
| `LOSS_PATTERN` | matches "Dang @bob, you lost 150000!" | Change if your loss messages are worded differently |

### !beg
| Variable | Default | What it does |
|---|---|---|
| `BEG_OUT` | `beg_award.txt` | Amount Mix It Up should pay out (0 = nothing) |
| `BEG_PERIOD_HOURS` | `6` | How long a beg round lasts, starting from your first beg |
| `BEGS_PER_PERIOD` | `3` | Begs per round; only one can pay |
| `BEG_TIERS` | ≤1k: 10k–50k, ≤10k: 4k–20k, ≤50k: 1k–5k, ≤999,999: 50–1k | Balance → gift range. 1,000,000+ gets nothing |
| `BEG_CHANCE` | `0.55` | Base odds a beg pays |
| `BEG_SUB_BONUS` / `BEG_SUB_MULT` | `0.15` / `1.25` | Subscriber odds bonus / gift multiplier |
| `BEG_ENGAGED_BONUS` / `BEG_LURKER_PENALTY` | `0.15` / `0.20` | Odds up for people who chat or gamble, down for beg-only lurkers |
| `BEG_HABIT_DAYS` / `BEG_HABIT_PENALTY` | `5` / `0.15` | Begging 5 of the last 7 days = serial beggar: worse odds, gets called out |
| `BEG_MOOD` | bitter 0.5/0.25, wine-drunk 0.6/0.35, manic 1.2/1.10, nostalgic 1.1/1.05 | Mood → (odds, gift size). Above 1.0 can beat the tier max by that much |

Rules: he only gives to people he likes (attitude CHILL). Each "no" comes with a reason. Three "no"s in a round earns a roast. A 4th beg gets "have some dignity", how long until the next round, and a small attitude bump.

### !tip
| Variable | Default | What it does |
|---|---|---|
| `TIP_OUT` | `tip_amount.txt` | Amount Mix It Up should take from the tipper (0 = nothing) |
| `TIP_MIN` | `100` | Smallest tip accepted |
| `TIP_ATTITUDE_PER_1000` | `5` | Attitude points forgiven per 1,000 tipped |
| `SUPERTIP` / `MEGATIP` | `25000` / `250000` | Tip sizes that wipe the slate clean and get the over-the-top treatment |
| `BAD_TIP_POINTS` | `10` | Tipping under `TIP_MIN` is an insult: refused, roasted, attitude goes up this much |
| `CHEAP_TIP_MIN_BALANCE` / `CHEAP_TIP_RATIO` / `CHEAP_TIP_POINTS` | `100000` / `0.01` / `5` | Anyone with 100k+ who tips under 1% of it is a cheapskate: tip taken, no forgiveness, attitude goes up 5 |

### Backups and errors
| Variable | Default | What it does |
|---|---|---|
| `BACKUP_DIR` / `BACKUP_DAYS` | `opie_backups` / `7` | A copy of `opie_state.json` is saved once a day; the last 7 are kept. If the main file is ever damaged, Opie restores the newest backup automatically (the broken one is kept as `opie_state.json.damaged`) |
| `ERROR_LOG` / `ERROR_LOG_MAX` | `opie_errors.log` / 200 KB | Anything that goes wrong is written here instead of chat. Send it to Claude when something breaks |

### Weird News
| Variable | Default | What it does |
|---|---|---|
| `NEWS_COOLDOWN` | `120` | Seconds between segments (owners skip it) |
| `NEWS_QUERIES` | "weird news", "florida man", ... | Searches picked at random each time |
| `NEWS_TIME_RANGE` | `"week"` | How recent: day, week, month or year |
| `NEWS_REMEMBER` | `50` | Stories remembered so he doesn't repeat one |
| `NEWS_SKIP` | death, kids, violence, ... | Stories mentioning these are skipped: tragedy isn't weird news |

### Attitude
| Variable | Default | What it does |
|---|---|---|
| `GRUDGE_HOURS` | `24` | Time for 100 to fade to 0 |
| `INSULT_POINTS` | `15` | Insult aimed at Opie |
| `SEVERE_POINTS` | `40` | "kys" and similar |
| `SPAM_POINTS` | `5` | Same line 3+ times |
| `IMPATIENT_POINTS` | `3` | Using the command on cooldown |
| `LLM_FLAG_POINTS` | `10` | Model tagged the message as hostile |
| `APOLOGY_POINTS` | `-20` | Apologising to Opie |
| `EXPLOIT_POINTS` | `45` | Exploit attempt (triple an insult) |
| `LEVELS` | 75 / 50 / 25 / 0 | Score thresholds for MUTINY / HOSTILE / ANNOYED / CHILL |
| `HOSTILE_REFUSE_CHANCE` | `0.5` | Refusal odds at HOSTILE |
| `HOSTILE_COOLDOWN_MULT` | `2` | Cooldown multiplier at HOSTILE and MUTINY |

### Word bank
| Variable | Default | What it does |
|---|---|---|
| `BANNED_WORDS` | `["boo"]` | Words Opie must never say. He's told not to, and any that slip through are cut from the reply. Lowercase, comma-separated, e.g. `["boo", "bestie"]` |

### Word lists (regex)
| Variable | What it matches |
|---|---|
| `OPIE_NAMES` | "opie", "@opieai", "opie-ai": triggers a reply |
| `NICKNAMES` | "op" at the start of a message (owners only) |
| `INSULTS` / `SEVERE` / `APOLOGY` | Attitude scoring |
| `EXPLOIT` | Exploit attempts |
| `SENSITIVE` | Reply content that gets swapped for a brush-off |

### Services
| Variable | Default | What it does |
|---|---|---|
| `SEARXNG_URL` | `http://localhost:8080/search` | Web search |
| `SEARCH_RESULTS` / `SNIPPET_CHARS` | `4` / `400` | How much search text the model sees |
| `OBS_HOST` / `OBS_PORT` | `localhost` / `4455` | OBS WebSocket (OBS → Tools → WebSocket Server Settings) |
| `OBS_PASSWORD_FILE` | `tip_amount.txt` | Amount the last `!tip` should take from the tipper, or 0 |
| `games.py` | Opie's games (Higher or Lower). Shares his brain with `ask.py`, so keep them in the same folder |
| `game_charge.txt` / `game_pay.txt` | What the last games command should take from / give to the player |
| `opie_backups\` | Daily copies of `opie_state.json` (last 7 days) |
| `opie_errors.log` | What went wrong, if anything. Send it to Claude when something breaks |
| `obs_password.txt` | File holding the OBS password |

OBS stats need a one-time `python -m pip install obsws-python`.
