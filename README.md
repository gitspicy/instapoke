# InstaPoke

A fast, browser-based counter lookup for Pokémon GO PVP. Set the 3 Pokémon
you're actually running, then during a battle just type a few letters of
what your opponent brought out and instantly see which of your 3 counters
it best.

Built for desktop/PC use as a quick reference while you play — no mobile
layout, no account, no backend.

## Running it

No build step, no server required:

- **Just open `index.html` in a browser** (double-click it, or drag it into a
  Chrome/Edge/Firefox window). Everything runs locally.
- If you'd rather serve it (e.g. to avoid your browser's local-file
  restrictions in some setups), any static file server works:
  ```
  python3 -m http.server 8000
  ```
  then visit `http://localhost:8000/`.

Your 3-Pokémon lineup (per league) is saved in the browser's `localStorage`,
so it's remembered next time you open the app on the same machine/browser.

## How to use it

1. Pick a league (Great / Ultra / Master) at the top.
2. Click **Edit Team** and set your 3 Pokémon for that league. Moves default
   to PvPoke's recommended competitive moveset — click into a slot to
   override fast/charged moves if you run something nonstandard.
3. During a battle, type into the **"Opponent is using…"** box. As soon as
   your 3 Pokémon and the match are unambiguous you can hit Enter (or click
   a suggestion) — results appear instantly, ranked best matchup to worst,
   with a WIN/LOSS call for each of the 3 shield scenarios (0/1/2 shields).
4. If you know the attacker actually has a different moveset than assumed,
   click "assume different moves" to correct it and results recompute live.

The search box stays focused so you can keep typing the next opponent's
name right away — no clicking required between lookups.

### Hands-free (voice) search

Click the 🎤 button next to the search box once (your browser will ask for
microphone permission the first time). It then listens continuously — just
say the opponent's name and results pop up automatically, no clicking or
typing needed, and it keeps listening for the next name right after. Click
the 🎤 again to turn it off.

Notes:
- Needs Chrome or Edge (the Web Speech API isn't supported in Firefox/Safari).
  The mic button is greyed out and disabled if your browser doesn't support it.
- Speech recognition can mishear tricky species names, so heard phrases are
  matched tolerantly (e.g. "register steel" → Registeel, "talon flame" →
  Talonflame) — if it guesses wrong, the status line under the search box
  shows exactly what it heard so you can just say the name again.
- Very short/filler sounds ("um", "the") are ignored rather than matched.

## Data & accuracy

- Pokémon stats, types, moves, and recommended movesets come from
  [PvPoke](https://github.com/pvpoke/pvpoke), the community-standard source
  for Pokémon GO PVP data. Run `python3 scripts/update-data.py` to refresh
  `data/gamemaster.js` and `data/movesets.js` from the latest PvPoke data
  (e.g. after a new season or new Pokémon release).
- Matchups are **not** looked up from a precomputed table — they're computed
  live by a battle simulator in `js/battle-sim.js`, using the real damage
  formula, energy/cooldown timing, and stat-stage buffs (Pokémon GO's actual
  PVP mechanics). This means every possible attacker vs. every Pokémon you
  could put on your team is covered, not just the "top 5" matchups PvPoke's
  site shows per Pokémon.
- Known simplification: both sides use a straightforward "fire your best
  affordable charged move as soon as you can" AI, not PvPoke's more nuanced
  bait/hold logic — so results are directionally reliable but won't always
  match pvpoke.com's numbers exactly.
- Known gap: a handful of Pokémon have unique one-off abilities that aren't
  represented in the move/stat data (e.g. Mimikyu's Disguise, which blocks
  its first hit). Those specific Pokémon may be rated worse than they really
  are.
- `scripts/test-sim.mjs` is a small dev-only sanity check that compares the
  simulator's win/loss direction against a few known PvPoke matchup ratings.
  Run it with `node scripts/test-sim.mjs` after touching `battle-sim.js`.
