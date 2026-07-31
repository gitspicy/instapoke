// Pokemon GO PVP battle simulator.
//
// Implements the core battle mechanics (stats, type effectiveness, damage
// formula, energy/cooldown timing, stat-stage buffs, shielding) following
// the same rules as PvPoke (https://github.com/pvpoke/pvpoke). This is a
// simplified re-implementation, not a copy of PvPoke's Battle.js: both
// sides use a straightforward "fire your best affordable charged move as
// soon as you can" AI rather than PvPoke's full bait/hold logic. That
// makes results directionally correct and fast to compute for every
// possible matchup on the fly, at the cost of not being pixel-identical
// to PvPoke's published ratings.
(function (global) {
  "use strict";

  const TYPE_CHART = {
    normal: { weak: ["fighting"], resist: [], immune: ["ghost"] },
    fighting: { weak: ["flying", "psychic", "fairy"], resist: ["rock", "bug", "dark"], immune: [] },
    flying: { weak: ["rock", "electric", "ice"], resist: ["fighting", "bug", "grass"], immune: ["ground"] },
    poison: { weak: ["ground", "psychic"], resist: ["fighting", "poison", "bug", "fairy", "grass"], immune: [] },
    ground: { weak: ["water", "grass", "ice"], resist: ["poison", "rock"], immune: ["electric"] },
    rock: { weak: ["fighting", "ground", "steel", "water", "grass"], resist: ["normal", "flying", "poison", "fire"], immune: [] },
    bug: { weak: ["flying", "rock", "fire"], resist: ["fighting", "ground", "grass"], immune: [] },
    ghost: { weak: ["ghost", "dark"], resist: ["poison", "bug"], immune: ["normal", "fighting"] },
    steel: { weak: ["fighting", "ground", "fire"], resist: ["normal", "flying", "rock", "bug", "steel", "grass", "psychic", "ice", "dragon", "fairy"], immune: ["poison"] },
    fire: { weak: ["ground", "rock", "water"], resist: ["bug", "steel", "fire", "grass", "ice", "fairy"], immune: [] },
    water: { weak: ["grass", "electric"], resist: ["steel", "fire", "water", "ice"], immune: [] },
    grass: { weak: ["flying", "poison", "bug", "fire", "ice"], resist: ["ground", "water", "grass", "electric"], immune: [] },
    electric: { weak: ["ground"], resist: ["flying", "steel", "electric"], immune: [] },
    psychic: { weak: ["bug", "ghost", "dark"], resist: ["fighting", "psychic"], immune: [] },
    ice: { weak: ["fighting", "fire", "steel", "rock"], resist: ["ice"], immune: [] },
    dragon: { weak: ["dragon", "ice", "fairy"], resist: ["fire", "water", "grass", "electric"], immune: [] },
    dark: { weak: ["fighting", "fairy", "bug"], resist: ["ghost", "dark"], immune: ["psychic"] },
    fairy: { weak: ["poison", "steel"], resist: ["fighting", "bug", "dark"], immune: ["dragon"] },
  };

  const DMG = {
    BONUS: 1.2999999523162841796875,
    SUPER_EFFECTIVE: 1.60000002384185791015625,
    RESISTED: 0.625,
    DOUBLE_RESISTED: 0.390625,
    STAB: 1.2000000476837158203125,
    SHADOW_ATK: 1.2,
    SHADOW_DEF: 0.83333331,
  };

  const MAX_BUFF_STAGE = 4;
  const BUFF_DIVISOR = 4;
  const MAX_TICKS = 480; // 240s real-match timer / 500ms per tick
  const SHIELD_SCENARIOS = [0, 1, 2];

  function getEffectiveness(moveType, defenderTypes) {
    let mult = 1;
    for (const t of defenderTypes) {
      const traits = TYPE_CHART[t];
      if (!traits) continue;
      if (traits.weak.includes(moveType)) mult *= DMG.SUPER_EFFECTIVE;
      else if (traits.resist.includes(moveType)) mult *= DMG.RESISTED;
      else if (traits.immune.includes(moveType)) mult *= DMG.DOUBLE_RESISTED;
    }
    return mult;
  }

  function buffMultiplier(stage) {
    stage = Math.max(-MAX_BUFF_STAGE, Math.min(MAX_BUFF_STAGE, stage));
    return stage >= 0 ? (BUFF_DIVISOR + stage) / BUFF_DIVISOR : BUFF_DIVISOR / (BUFF_DIVISOR - stage);
  }

  function getCPM(gm, level) {
    const idx = Math.round((level - 1) * 2);
    return gm.cpMultipliers[Math.max(0, Math.min(gm.cpMultipliers.length - 1, idx))];
  }

  function calcCP(base, ivs, cpm) {
    return Math.floor(
      (base.atk + ivs.atk) * Math.sqrt(base.def + ivs.def) * Math.sqrt(base.hp + ivs.hp) * cpm * cpm / 10
    );
  }

  // Pick sensible default level/IVs for a league when PvPoke's precomputed
  // "rank #1" spread isn't available (unranked / very new species).
  function fallbackLevelIVs(gm, base, cpCap) {
    if (cpCap === null) return { level: 50, ivs: { atk: 15, def: 15, hp: 15 } };
    let level = 51;
    const ivs = { atk: 0, def: 15, hp: 15 };
    while (level >= 1) {
      const cpm = getCPM(gm, level);
      if (calcCP(base, ivs, cpm) <= cpCap) return { level, ivs };
      level -= 0.5;
    }
    return { level: 1, ivs };
  }

  function cpCapForLeague(league) {
    if (league === "great") return 1500;
    if (league === "ultra") return 2500;
    return null; // master: uncapped
  }

  function defaultIVKeyForLeague(league) {
    if (league === "great") return "cp1500";
    if (league === "ultra") return "cp2500";
    return null;
  }

  // Build a ready-to-simulate "battler" from a species + chosen moveset/league.
  function buildBattler(gm, speciesId, league, overrides) {
    const p = gm.pokemon[speciesId];
    if (!p) return null;
    overrides = overrides || {};

    const ivKey = defaultIVKeyForLeague(league);
    let level, ivs;
    if (overrides.level && overrides.ivs) {
      level = overrides.level;
      ivs = overrides.ivs;
    } else if (ivKey && p.defaultIVs && p.defaultIVs[ivKey]) {
      const [lvl, ivAtk, ivDef, ivHp] = p.defaultIVs[ivKey];
      level = lvl;
      ivs = { atk: ivAtk, def: ivDef, hp: ivHp };
    } else {
      const fallback = fallbackLevelIVs(gm, p.baseStats, cpCapForLeague(league));
      level = fallback.level;
      ivs = fallback.ivs;
    }

    const fastMoveId = overrides.fastMove || recommendedMoveset(speciesId, league).moveset[0];
    const chargedIds = overrides.chargedMoves || recommendedMoveset(speciesId, league).moveset.slice(1, 3);

    const fastMove = gm.moves[fastMoveId];
    const chargedMoves = chargedIds.map((id) => gm.moves[id]).filter(Boolean);
    if (!fastMove || chargedMoves.length === 0) return null;

    const cpm = getCPM(gm, level);
    const atk = (p.baseStats.atk + ivs.atk) * cpm * (p.shadow ? DMG.SHADOW_ATK : 1);
    const def = (p.baseStats.def + ivs.def) * cpm * (p.shadow ? DMG.SHADOW_DEF : 1);
    const hp = Math.max(Math.floor((p.baseStats.hp + ivs.hp) * cpm), 10);

    return {
      speciesId,
      name: p.speciesName,
      types: p.types,
      level,
      ivs,
      cp: calcCP(p.baseStats, ivs, cpm),
      atk,
      def,
      hp,
      fastMove,
      chargedMoves,
    };
  }

  function recommendedMoveset(speciesId, league) {
    const md = (global.MOVESET_DATA && global.MOVESET_DATA[league]) || {};
    if (md[speciesId]) return md[speciesId];

    // Fallback heuristic for species PvPoke hasn't ranked in this league.
    const gm = global.GM_DATA;
    const p = gm.pokemon[speciesId];
    const fast = p.fastMoves
      .map((id) => gm.moves[id])
      .filter(Boolean)
      .sort((a, b) => (b.power / b.turns + b.energyGain / b.turns) - (a.power / a.turns + a.energyGain / a.turns))[0];
    const charged = p.chargedMoves
      .map((id) => gm.moves[id])
      .filter(Boolean)
      .sort((a, b) => b.power / Math.max(b.energy, 1) - a.power / Math.max(a.energy, 1))
      .slice(0, 2);
    return { moveset: [fast.moveId, ...charged.map((m) => m.moveId)] };
  }

  function calcDamage(attackerBattler, attackerState, defenderBattler, defenderState, move) {
    const atkMult = buffMultiplier(attackerState.atkStage);
    const defMult = buffMultiplier(defenderState.defStage);
    const attack = attackerBattler.atk * atkMult;
    const defense = defenderBattler.def * defMult;
    const stab = attackerBattler.types.includes(move.type) ? DMG.STAB : 1;
    const eff = getEffectiveness(move.type, defenderBattler.types);
    return Math.floor(move.power * stab * (attack / defense) * eff * 0.5 * DMG.BONUS) + 1;
  }

  function maybeApplyBuff(actorBattler, actorState, targetState, move) {
    if (!move.buffs || move.buffApplyChance === null || move.buffApplyChance < 0.99) return;
    const [atkDelta, defDelta] = move.buffs;
    const dest = move.buffTarget === "opponent" ? targetState : actorState;
    dest.atkStage = Math.max(-MAX_BUFF_STAGE, Math.min(MAX_BUFF_STAGE, dest.atkStage + atkDelta));
    dest.defStage = Math.max(-MAX_BUFF_STAGE, Math.min(MAX_BUFF_STAGE, dest.defStage + defDelta));
  }

  function chooseCharge(battler, state) {
    const affordable = battler.chargedMoves.filter((m) => m.energy <= state.energy);
    if (affordable.length === 0) return null;
    affordable.sort((a, b) => b.power - a.power || a.energy - b.energy);
    return affordable[0];
  }

  function initState(shields) {
    return { hp: null, energy: 0, atkStage: 0, defStage: 0, cooldown: null, shields, fainted: false };
  }

  // Simulate one matchup under a fixed shield count for each side.
  // Returns { winner: 'a'|'b'|'tie', ticks, hpA, maxHpA, hpB, maxHpB }
  function simulate(battlerA, battlerB, shieldsA, shieldsB) {
    const A = { battler: battlerA, state: initState(shieldsA) };
    const B = { battler: battlerB, state: initState(shieldsB) };
    A.state.hp = battlerA.hp;
    B.state.hp = battlerB.hp;
    A.state.cooldown = battlerA.fastMove.turns;
    B.state.cooldown = battlerB.fastMove.turns;

    for (let tick = 0; tick < MAX_TICKS; tick++) {
      if (!A.state.fainted) A.state.cooldown--;
      if (!B.state.fainted) B.state.cooldown--;

      const aFast = !A.state.fainted && A.state.cooldown <= 0;
      const bFast = !B.state.fainted && B.state.cooldown <= 0;

      const aFastDmg = aFast ? calcDamage(A.battler, A.state, B.battler, B.state, A.battler.fastMove) : 0;
      const bFastDmg = bFast ? calcDamage(B.battler, B.state, A.battler, A.state, B.battler.fastMove) : 0;

      if (aFast) {
        B.state.hp -= aFastDmg;
        A.state.energy = Math.min(100, A.state.energy + A.battler.fastMove.energyGain);
        maybeApplyBuff(A.battler, A.state, B.state, A.battler.fastMove);
      }
      if (bFast) {
        A.state.hp -= bFastDmg;
        B.state.energy = Math.min(100, B.state.energy + B.battler.fastMove.energyGain);
        maybeApplyBuff(B.battler, B.state, A.state, B.battler.fastMove);
      }

      A.state.fainted = A.state.fainted || A.state.hp <= 0;
      B.state.fainted = B.state.fainted || B.state.hp <= 0;
      if (A.state.fainted || B.state.fainted) return finish(A, B, tick);

      const aCharge = aFast ? chooseCharge(A.battler, A.state) : null;
      const bCharge = bFast ? chooseCharge(B.battler, B.state) : null;

      let aChargeDmgToB = 0;
      let bChargeDmgToA = 0;

      if (aCharge) {
        A.state.energy -= aCharge.energy;
        if (B.state.shields > 0) {
          B.state.shields--;
          aChargeDmgToB = 1;
        } else {
          aChargeDmgToB = calcDamage(A.battler, A.state, B.battler, B.state, aCharge);
        }
        maybeApplyBuff(A.battler, A.state, B.state, aCharge);
      }
      if (bCharge) {
        B.state.energy -= bCharge.energy;
        if (A.state.shields > 0) {
          A.state.shields--;
          bChargeDmgToA = 1;
        } else {
          bChargeDmgToA = calcDamage(B.battler, B.state, A.battler, A.state, bCharge);
        }
        maybeApplyBuff(B.battler, B.state, A.state, bCharge);
      }

      if (aCharge) B.state.hp -= aChargeDmgToB;
      if (bCharge) A.state.hp -= bChargeDmgToA;

      if (aFast) A.state.cooldown = A.battler.fastMove.turns;
      if (bFast) B.state.cooldown = B.battler.fastMove.turns;

      A.state.fainted = A.state.fainted || A.state.hp <= 0;
      B.state.fainted = B.state.fainted || B.state.hp <= 0;
      if (A.state.fainted || B.state.fainted) return finish(A, B, tick);
    }
    return finish(A, B, MAX_TICKS, true);
  }

  function finish(A, B, ticks, timedOut) {
    const hpA = Math.max(0, A.state.hp);
    const hpB = Math.max(0, B.state.hp);
    let winner;
    if (hpA <= 0 && hpB <= 0) winner = "tie";
    else if (hpA <= 0) winner = "b";
    else if (hpB <= 0) winner = "a";
    else winner = hpA >= hpB ? "a" : "b"; // timeout: higher HP% wins
    return {
      winner,
      ticks,
      timedOut: !!timedOut,
      hpA,
      maxHpA: A.battler.hp,
      hpB,
      maxHpB: B.battler.hp,
    };
  }

  // Evaluate defender vs attacker across the standard 0/1/2-shield scenarios.
  // Returns per-scenario results plus a single averaged "score" in [-100,100]
  // (defender HP% remaining minus attacker HP% remaining) for ranking.
  function evaluateMatchup(defenderBattler, attackerBattler) {
    const scenarios = SHIELD_SCENARIOS.map((shields) => {
      const result = simulate(defenderBattler, attackerBattler, shields, shields);
      const defHpPct = (result.hpA / result.maxHpA) * 100;
      const atkHpPct = (result.hpB / result.maxHpB) * 100;
      return {
        shields,
        winner: result.winner === "a" ? "defender" : result.winner === "b" ? "attacker" : "tie",
        defenderHpPct: defHpPct,
        attackerHpPct: atkHpPct,
        score: defHpPct - atkHpPct,
        timedOut: result.timedOut,
      };
    });
    const avgScore = scenarios.reduce((s, x) => s + x.score, 0) / scenarios.length;
    return { scenarios, avgScore };
  }

  global.BattleSim = {
    getCPM,
    calcCP,
    buildBattler,
    recommendedMoveset,
    simulate,
    evaluateMatchup,
    cpCapForLeague,
    TYPE_CHART,
  };
})(window);
