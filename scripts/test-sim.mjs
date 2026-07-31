// Quick sanity check for js/battle-sim.js — not part of the app, dev-only.
import fs from "node:fs";
import vm from "node:vm";

const sandbox = {};
sandbox.window = sandbox;
sandbox.console = console;
vm.createContext(sandbox);

for (const f of ["data/gamemaster.js", "data/movesets.js", "js/battle-sim.js"]) {
  vm.runInContext(fs.readFileSync(f, "utf8"), sandbox, { filename: f });
}

const { GM_DATA, BattleSim } = sandbox;

function matchup(league, defenderId, attackerId) {
  const def = BattleSim.buildBattler(GM_DATA, defenderId, league);
  const atk = BattleSim.buildBattler(GM_DATA, attackerId, league);
  if (!def) throw new Error("no battler for " + defenderId);
  if (!atk) throw new Error("no battler for " + attackerId);
  const res = BattleSim.evaluateMatchup(def, atk);
  console.log(
    `${league.padEnd(6)} ${defenderId.padEnd(20)} vs ${attackerId.padEnd(20)} avg=${res.avgScore.toFixed(1)}`,
    res.scenarios.map((s) => `${s.shields}sh:${s.winner[0]}(${s.score.toFixed(0)})`).join(" ")
  );
  return res;
}

console.log("--- Known lopsided matchups (from PvPoke rankings-1500.json) ---");
// mimikyu counters (beats mimikyu): lickilicky rating 309 for mimikyu means mimikyu LOSES badly to lickilicky
matchup("great", "lickilicky", "mimikyu"); // expect lickilicky (defender) WINS
matchup("great", "mimikyu", "lickilicky"); // expect mimikyu (defender) LOSES
// mimikyu matchups (mimikyu wins): altaria rating 859 means mimikyu crushes altaria
matchup("great", "mimikyu", "altaria"); // expect mimikyu WINS big
matchup("great", "altaria", "mimikyu"); // expect altaria LOSES

console.log("\n--- Basic type-advantage sanity (Great League, generic movesets) ---");
matchup("great", "swampert", "charizard"); // water/ground vs fire -> should favor swampert
matchup("great", "charizard", "swampert"); // should favor swampert as attacker too (charizard loses)

console.log("\n--- Master League uncapped stats sanity ---");
matchup("master", "metagross", "dialga");

console.log("\n--- Species not in rankings (fallback moveset) ---");
try {
  matchup("great", "bulbasaur", "mimikyu");
} catch (e) {
  console.log("ERROR:", e.message);
}
