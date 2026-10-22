# Opie AI

YouTube chat bot that runs on a local Ollama model, with SearXNG for web search.

## Bot wiring

| Trigger | Command |
|---|---|
| **Every chat message** (new) | `python ask.py --log "<user>" "<message>"` |
| `!opie-ai <question>` | `python ask.py "<user>" <question>` → reply written to `ai_reply.txt` |
| `!opie-forgive <user>` (mods only) | `python ask.py --forgive "<user>"` → writes to `ai_reply.txt` |
| `!opie-mood <user>` (mods only) | `python ask.py --mood "<user>"` → writes to `ai_reply.txt` |
| `!opie-chime on/off` (mods only) | `python ask.py --chime on` / `off` → writes to `ai_reply.txt` |
| **File watcher on `ai_chime.txt`** (new) | read the file and send it to chat |

`--log` sometimes makes Opie talk: follow-ups and unprompted chime-ins go to `ai_chime.txt`, not `ai_reply.txt`.
Set up a file-watcher trigger on that file that posts its contents. Don't make the bot wait for `--log` to finish,
because a reply takes a few seconds.

Use `pythonw` on Windows to stop a console window from flashing for every chat line.
Everything is stored in `opie_state.json` next to the script. Delete it to wipe all memory.
This replaces the old `cooldowns.json`.

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
- **Disobedience meter (0–100 per user):**
  - Goes up for insults aimed at Opie (+15), severe stuff like "kys" (+40), the same line spammed 3+ times (+5),
    using the command during cooldown (+3), and when the model tags a question as hostile (+10).
  - An apology to Opie takes 20 off, and it drains by 0.5 per minute on its own.
  - Levels: `CHILL` 0–24 → `ANNOYED` 25–49 (saltier) → `HOSTILE` 50–74 (double cooldown,
    50% refusal) → `MUTINY` 75+ (refuses with an in-character burn).
  - Users in `EXEMPT` never build it up.
- **Web search is skipped** when the question is about chat, or when Opie is refusing anyway.

All the numbers and word lists are at the top of `ask.py`.
