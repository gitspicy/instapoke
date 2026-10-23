# Opie AI

YouTube chat bot that runs on a local Ollama model, with SearXNG for web search.

## Bot wiring (Mix It Up)

Every reply is written to a file **and** printed, so Mix It Up can post the External Program output directly.

1. **Every chat message:** Events → *Chat Message Received* → add an **External Program** action:
   - Program: `python` (full path to `python.exe` if needed; **not** `pythonw`, which has no output)
   - Arguments: `"D:\DOODA\streamscripts\ask.py" --log "$username" "$message"`
   - Tick **Wait For Finish** and **Save Output**; untick Show Window
   - Then a **Conditional** action: if `$externalprogramresult` is not empty → **Chat** action posting `$externalprogramresult`

   It only prints something when Opie decides to talk (a message mentioning him, or a chime-in), so chat stays quiet otherwise.
2. **`!opie-ai`:** keep your existing command (`ask.py "$username" $allargs`).
3. **Mod commands** (custom commands, Mods role, same External Program → Chat pattern):

| Chat command | Arguments |
|---|---|
| `!opie-mood <user>` | `ask.py --mood "$arg1text"` |
| `!opie-forgive <user>` | `ask.py --forgive "$arg1text"` |
| `!opie-chime on/off` | `ask.py --chime $arg1text` |

Everything is stored in `opie_state.json` next to the script. Delete it to wipe all memory.

## Features

- **Chat memory:** keeps the last 100 chat lines (including Opie's own replies) and gives the model the
  most recent 25. Opie can answer "what did Steve say earlier?" and react to what's happening.
- **Per-user memory:** the last 3 Q&As per viewer, so follow-ups like "and the sequel?" work.
- **Talk to him in chat:** any message that mentions Opie (`opie ...`, `@OpieAI ...`) gets a reply without the command.
  For 3 minutes after Opie answers someone, their cooldown drops to 15s so back-and-forth flows.
  Mentions while on cooldown are ignored quietly (no cooldown spam), and Opie's own messages echoing back are ignored.
- **Chiming in:** when chat is lively (8+ lines in 2 min), Opie has been quiet for 5+ min and 15+ lines, each new
  line has a 15% chance of Opie jumping in with a comment or hot take on what chat's talking about. He likes
  taking shots at whoever's on his bad side.
- **Attitude meter (0–100 per user):**
  - Goes up for insults aimed at Opie (+15), severe stuff like "kys" (+40), the same line spammed 3+ times (+5),
    using the command during cooldown (+3), and when the model tags a question as hostile (+10).
  - An apology to Opie takes 20 off, and it drains by 0.5 per minute on its own.
  - Levels: `CHILL` 0–24 → `ANNOYED` 25–49 (saltier) → `HOSTILE` 50–74 (double cooldown,
    50% refusal) → `MUTINY` 75+ (refuses with an in-character burn).
  - Users in `EXEMPT` never build it up.
- **Web search is skipped** when the question is about chat, or when Opie is refusing anyway.

All the numbers and word lists are at the top of `ask.py`.
