# Opie AI

YouTube chat bot that runs on a local Ollama model, with SearXNG for web search.

## Bot wiring

| Trigger | Command |
|---|---|
| **Every chat message** (new) | `python ask.py --log "<user>" "<message>"` |
| `!opie-ai <question>` | `python ask.py "<user>" <question>` → reply written to `ai_reply.txt` |
| `!opie-forgive <user>` (mods only) | `python ask.py --forgive "<user>"` → writes to `ai_reply.txt` |
| `!opie-mood <user>` (mods only) | `python ask.py --mood "<user>"` → writes to `ai_reply.txt` |

Use `pythonw` on Windows to stop a console window from flashing for every chat line.
Everything is stored in `opie_state.json` next to the script. Delete it to wipe all memory.
This replaces the old `cooldowns.json`.

## Features

- **Chat memory:** keeps the last 100 chat lines (including Opie's own replies) and gives the model the
  most recent 25. Opie can answer "what did Steve say earlier?" and react to what's happening.
- **Per-user memory:** the last 3 Q&As per viewer, so follow-ups like "and the sequel?" work.
- **Disobedience meter (0–100 per user):**
  - Goes up for insults aimed at Opie (+15), severe stuff like "kys" (+40), the same line spammed 3+ times (+5),
    using the command during cooldown (+3), and when the model tags a question as hostile (+10).
  - An apology to Opie takes 20 off, and it drains by 0.5 per minute on its own.
  - Levels: `CHILL` 0–24 → `ANNOYED` 25–49 (saltier) → `HOSTILE` 50–74 (double cooldown,
    50% refusal) → `MUTINY` 75+ (refuses with an in-character burn).
  - Users in `EXEMPT` never build it up.
- **Web search is skipped** when the question is about chat, or when Opie is refusing anyway.

All the numbers and word lists are at the top of `ask.py`.
