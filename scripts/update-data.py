#!/usr/bin/env python3
"""
Refresh the local Pokemon/move/moveset data from the PvPoke repo
(https://github.com/pvpoke/pvpoke), which is the community-standard,
frequently-updated source for Pokemon GO PVP data.

Run this occasionally (e.g. once a season) to pick up newly released
Pokemon, move balance changes, and updated "meta" movesets:

    python3 scripts/update-data.py

It writes:
  - data/gamemaster.js   (Pokemon base stats/types/moves + move data + CPM table)
  - data/movesets.js     (PvPoke's recommended competitive moveset per Pokemon per league)
"""
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA_DIR = ROOT / "data"

RAW_BASE = "https://raw.githubusercontent.com/pvpoke/pvpoke/master"
GAMEMASTER_URL = f"{RAW_BASE}/src/data/gamemaster.json"
RANKINGS = {
    "great": f"{RAW_BASE}/src/data/rankings/all/overall/rankings-1500.json",
    "ultra": f"{RAW_BASE}/src/data/rankings/all/overall/rankings-2500.json",
    "master": f"{RAW_BASE}/src/data/rankings/all/overall/rankings-10000.json",
}

# Official Niantic CP Multiplier table, indexed by (level-1)*2 for half levels.
# Sourced from PvPoke's src/js/pokemon/Pokemon.js (the `cpms` constant).
CPMS = [
    0.0939999967813491, 0.135137430784308, 0.166397869586944, 0.192650914456886,
    0.215732470154762, 0.236572655026622, 0.255720049142837, 0.273530381100769,
    0.290249884128570, 0.306057381335773, 0.321087598800659, 0.335445032295077,
    0.349212676286697, 0.362457748778790, 0.375235587358474, 0.387592411085168,
    0.399567276239395, 0.411193549517250, 0.422500014305114, 0.432926413410414,
    0.443107545375824, 0.453059953871985, 0.462798386812210, 0.472336077786704,
    0.481684952974319, 0.490855810259008, 0.499858438968658, 0.508701756943992,
    0.517393946647644, 0.525942508771329, 0.534354329109191, 0.542635762230353,
    0.550792694091796, 0.558830599438087, 0.566754519939422, 0.574569148039264,
    0.582278907299041, 0.589887911977272, 0.597400009632110, 0.604823657502073,
    0.612157285213470, 0.619404110566050, 0.626567125320434, 0.633649181622743,
    0.640652954578399, 0.647580963301656, 0.654435634613037, 0.661219263506722,
    0.667934000492096, 0.674581899290818, 0.681164920330047, 0.687684905887771,
    0.694143652915954, 0.700542893277978, 0.706884205341339, 0.713169102333341,
    0.719399094581604, 0.725575616972598, 0.731700003147125, 0.734741011137376,
    0.737769484519958, 0.740785574597326, 0.743789434432983, 0.746781208702482,
    0.749761044979095, 0.752729105305821, 0.755685508251190, 0.758630366519684,
    0.761563837528228, 0.764486065255226, 0.767397165298461, 0.770297273971590,
    0.773186504840850, 0.776064945942412, 0.778932750225067, 0.781790064808426,
    0.784636974334716, 0.787473583646825, 0.790300011634826, 0.792803950958807,
    0.795300006866455, 0.797803921486970, 0.800300002098083, 0.802803892322847,
    0.805299997329711, 0.807803863460723, 0.810299992561340, 0.812803834895026,
    0.815299987792968, 0.817803806620319, 0.820299983024597, 0.822803778631297,
    0.825299978256225, 0.827803750922782, 0.830299973487854, 0.832803753381377,
    0.835300028324127, 0.837803755931569, 0.840300023555755, 0.842803729034748,
    0.845300018787384, 0.847803702398935, 0.850300014019012, 0.852803676019539,
    0.855300009250640, 0.857803649892077, 0.860300004482269, 0.862803624012168,
    0.865299999713897,
]


def fetch_json(url: str):
    print(f"Fetching {url}")
    result = subprocess.run(
        ["curl", "-sS", "--max-time", "60", url],
        capture_output=True, check=True,
    )
    return json.loads(result.stdout)


def build_gamemaster():
    gm = fetch_json(GAMEMASTER_URL)

    moves = {}
    for m in gm["moves"]:
        moves[m["moveId"]] = {
            "moveId": m["moveId"],
            "name": m["name"],
            "type": m["type"],
            "power": m.get("power", 0),
            "energy": m.get("energy", 0),
            "energyGain": m.get("energyGain", 0),
            "turns": m.get("turns", 1),
            "buffs": m.get("buffs"),
            "buffTarget": m.get("buffTarget"),
            "buffApplyChance": float(m["buffApplyChance"]) if m.get("buffApplyChance") is not None else None,
        }

    pokemon = {}
    for p in gm["pokemon"]:
        if not p.get("released", False):
            continue
        if not p.get("baseStats"):
            continue
        tags = p.get("tags", [])
        pokemon[p["speciesId"]] = {
            "speciesId": p["speciesId"],
            "speciesName": p["speciesName"],
            "dex": p.get("dex"),
            "baseStats": p["baseStats"],
            "types": p.get("types", []),
            "fastMoves": p.get("fastMoves", []),
            "chargedMoves": p.get("chargedMoves", []),
            "eliteFastMoves": p.get("eliteMoves", []) if False else p.get("eliteFastMoves", []),
            "eliteChargedMoves": p.get("eliteChargedMoves", []),
            "tags": tags,
            "shadow": "shadow" in tags,
            "mega": "mega" in tags,
            "legendary": "legendary" in tags,
            "mythical": "mythical" in tags,
            "defaultIVs": p.get("defaultIVs", {}),
        }

    out = {
        "generatedAt": gm.get("timestamp"),
        "cpMultipliers": CPMS,
        "pokemon": pokemon,
        "moves": moves,
    }

    js = "// Auto-generated by scripts/update-data.py from pvpoke/pvpoke. Do not hand-edit.\n"
    js += "window.GM_DATA = " + json.dumps(out, separators=(",", ":")) + ";\n"
    (DATA_DIR / "gamemaster.js").write_text(js)
    print(f"Wrote data/gamemaster.js ({len(pokemon)} pokemon, {len(moves)} moves)")


def build_movesets():
    out = {}
    for league, url in RANKINGS.items():
        rankings = fetch_json(url)
        league_out = {}
        for entry in rankings:
            moveset = entry.get("moveset")
            if not moveset or len(moveset) < 2:
                continue
            league_out[entry["speciesId"]] = {
                "moveset": moveset,
                "score": entry.get("score"),
            }
        out[league] = league_out
        print(f"  {league}: {len(league_out)} recommended movesets")

    js = "// Auto-generated by scripts/update-data.py from pvpoke/pvpoke. Do not hand-edit.\n"
    js += "window.MOVESET_DATA = " + json.dumps(out, separators=(",", ":")) + ";\n"
    (DATA_DIR / "movesets.js").write_text(js)
    print("Wrote data/movesets.js")


def main():
    DATA_DIR.mkdir(exist_ok=True)
    build_gamemaster()
    build_movesets()
    print("Done.")


if __name__ == "__main__":
    sys.exit(main())
