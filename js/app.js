(function () {
  "use strict";

  const GM = window.GM_DATA;
  const LEAGUES = ["great", "ultra", "master"];
  const STORAGE_KEY = "instapoke_state_v1";

  const POKEMON_LIST = Object.values(GM.pokemon)
    .map((p) => ({ speciesId: p.speciesId, speciesName: p.speciesName }))
    .sort((a, b) => a.speciesName.localeCompare(b.speciesName));

  // ---------- state ----------

  function defaultState() {
    return {
      activeLeague: "great",
      teams: { great: [null, null, null], ultra: [null, null, null], master: [null, null, null] },
    };
  }

  function loadState() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return defaultState();
      const parsed = JSON.parse(raw);
      const base = defaultState();
      return {
        activeLeague: LEAGUES.includes(parsed.activeLeague) ? parsed.activeLeague : "great",
        teams: Object.assign(base.teams, parsed.teams),
      };
    } catch (e) {
      return defaultState();
    }
  }

  function saveState() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  }

  let state = loadState();
  let currentAttacker = null; // { speciesId, fastMove, chargedMoves: [id,id] }

  // ---------- search matching ----------

  function searchPokemon(query, limit) {
    const q = query.trim().toLowerCase();
    if (!q) return [];
    const starts = [];
    const contains = [];
    for (const p of POKEMON_LIST) {
      const name = p.speciesName.toLowerCase();
      if (name.startsWith(q)) starts.push(p);
      else if (name.includes(q)) contains.push(p);
      if (starts.length + contains.length > 200) break;
    }
    return starts.concat(contains).slice(0, limit || 8);
  }

  // ---------- move helpers ----------

  function movePool(speciesId) {
    const p = GM.pokemon[speciesId];
    return {
      fast: [...new Set([...p.fastMoves, ...(p.eliteFastMoves || [])])],
      charged: [...new Set([...p.chargedMoves, ...(p.eliteChargedMoves || [])])],
    };
  }

  function moveName(id) {
    const m = GM.moves[id];
    return m ? m.name : id;
  }

  function movesetFor(slot, league) {
    if (slot.fastMove && slot.chargedMoves && slot.chargedMoves.length === 2) {
      return { moveset: [slot.fastMove, ...slot.chargedMoves] };
    }
    return BattleSim.recommendedMoveset(slot.speciesId, league);
  }

  function makeSlot(speciesId, league) {
    const rec = BattleSim.recommendedMoveset(speciesId, league);
    return { speciesId, fastMove: rec.moveset[0], chargedMoves: rec.moveset.slice(1, 3) };
  }

  // ---------- rendering: league switch ----------

  const leagueSwitchEl = document.getElementById("league-switch");
  leagueSwitchEl.addEventListener("click", (e) => {
    const btn = e.target.closest(".league-btn");
    if (!btn) return;
    state.activeLeague = btn.dataset.league;
    saveState();
    renderAll();
  });

  function renderLeagueSwitch() {
    leagueSwitchEl.querySelectorAll(".league-btn").forEach((btn) => {
      btn.classList.toggle("active", btn.dataset.league === state.activeLeague);
    });
  }

  // ---------- team panel ----------

  const teamPanelEl = document.getElementById("team-panel");
  const teamSlotsEl = document.getElementById("team-slots");
  const teamToggleBtn = document.getElementById("team-toggle");
  const emptyStateEl = document.getElementById("empty-state");

  teamToggleBtn.addEventListener("click", () => {
    teamPanelEl.classList.toggle("hidden");
  });
  document.getElementById("empty-edit-team").addEventListener("click", () => {
    teamPanelEl.classList.remove("hidden");
  });

  function currentTeam() {
    return state.teams[state.activeLeague];
  }

  function renderTeamPanel() {
    teamPanelEl.querySelector(".team-panel-league").textContent =
      "(" + leagueLabel(state.activeLeague) + ")";

    const team = currentTeam();
    teamSlotsEl.innerHTML = "";
    team.forEach((slot, idx) => {
      const card = document.createElement("div");
      card.className = "team-slot" + (slot ? " filled" : "");
      if (!slot) {
        card.innerHTML = '<div class="slot-empty">+ Add Pokémon</div>';
        card.addEventListener("click", () => openPicker((speciesId) => {
          team[idx] = makeSlot(speciesId, state.activeLeague);
          saveState();
          renderAll();
        }));
      } else {
        const p = GM.pokemon[slot.speciesId];
        const pool = movePool(slot.speciesId);
        card.innerHTML = `
          <div class="slot-name">${p.speciesName}</div>
          <div class="slot-meta">CP cap: ${BattleSim.cpCapForLeague(state.activeLeague) || "None"}</div>
          <div class="slot-moves">
            <div class="slot-move-row">
              <span>⚡</span>
              <select data-role="fast"></select>
            </div>
            <div class="slot-move-row">
              <span>①</span>
              <select data-role="charged1"></select>
            </div>
            <div class="slot-move-row">
              <span>②</span>
              <select data-role="charged2"></select>
            </div>
          </div>
          <div class="slot-actions">
            <button data-action="swap">Change</button>
            <button data-action="remove">Remove</button>
          </div>
        `;
        fillMoveSelect(card.querySelector('[data-role="fast"]'), pool.fast, slot.fastMove);
        fillMoveSelect(card.querySelector('[data-role="charged1"]'), pool.charged, slot.chargedMoves[0], slot.chargedMoves[1]);
        fillMoveSelect(card.querySelector('[data-role="charged2"]'), pool.charged, slot.chargedMoves[1], slot.chargedMoves[0], true);

        card.querySelector('[data-role="fast"]').addEventListener("change", (e) => {
          slot.fastMove = e.target.value;
          saveState();
          recomputeMatchupIfActive();
        });
        card.querySelector('[data-role="charged1"]').addEventListener("change", (e) => {
          slot.chargedMoves[0] = e.target.value;
          saveState();
          recomputeMatchupIfActive();
        });
        card.querySelector('[data-role="charged2"]').addEventListener("change", (e) => {
          slot.chargedMoves[1] = e.target.value || null;
          saveState();
          recomputeMatchupIfActive();
        });
        card.querySelector('[data-action="remove"]').addEventListener("click", (e) => {
          e.stopPropagation();
          team[idx] = null;
          saveState();
          renderAll();
        });
        card.querySelector('[data-action="swap"]').addEventListener("click", (e) => {
          e.stopPropagation();
          openPicker((speciesId) => {
            team[idx] = makeSlot(speciesId, state.activeLeague);
            saveState();
            renderAll();
          });
        });
      }
      teamSlotsEl.appendChild(card);
    });
  }

  function fillMoveSelect(select, ids, selectedId, excludeId, allowNone) {
    select.innerHTML = "";
    if (allowNone) {
      const noneOpt = document.createElement("option");
      noneOpt.value = "";
      noneOpt.textContent = "No extra move";
      if (!selectedId) noneOpt.selected = true;
      select.appendChild(noneOpt);
    }
    ids.forEach((id) => {
      if (id === excludeId) return;
      const opt = document.createElement("option");
      opt.value = id;
      opt.textContent = moveName(id);
      if (id === selectedId) opt.selected = true;
      select.appendChild(opt);
    });
  }

  function leagueLabel(league) {
    if (league === "great") return "Great League · CP 1500";
    if (league === "ultra") return "Ultra League · CP 2500";
    return "Master League · No CP cap";
  }

  // ---------- picker modal ----------

  const pickerModal = document.getElementById("picker-modal");
  const pickerSearch = document.getElementById("picker-search");
  const pickerResults = document.getElementById("picker-results");
  let pickerCallback = null;

  function openPicker(callback) {
    pickerCallback = callback;
    pickerModal.classList.remove("hidden");
    pickerSearch.value = "";
    pickerResults.innerHTML = "";
    pickerSearch.focus();
  }

  function closePicker() {
    pickerModal.classList.add("hidden");
    pickerCallback = null;
  }

  document.getElementById("picker-close").addEventListener("click", closePicker);
  pickerModal.addEventListener("click", (e) => {
    if (e.target === pickerModal) closePicker();
  });
  pickerSearch.addEventListener("input", () => {
    const matches = searchPokemon(pickerSearch.value, 10);
    pickerResults.innerHTML = "";
    matches.forEach((m) => {
      const li = document.createElement("li");
      li.textContent = m.speciesName;
      li.addEventListener("click", () => {
        if (pickerCallback) pickerCallback(m.speciesId);
        closePicker();
      });
      pickerResults.appendChild(li);
    });
  });
  pickerSearch.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closePicker();
    if (e.key === "Enter") {
      const first = pickerResults.querySelector("li");
      if (first) first.click();
    }
  });

  // ---------- attacker search ----------

  const searchInput = document.getElementById("attacker-search");
  const searchResultsEl = document.getElementById("search-results");
  let activeIndex = -1;
  let currentMatches = [];

  function handleSearchTextChanged() {
    currentMatches = searchPokemon(searchInput.value, 8);
    activeIndex = -1;
    renderSearchDropdown();
  }

  searchInput.addEventListener("input", handleSearchTextChanged);

  searchInput.addEventListener("keydown", (e) => {
    if (currentMatches.length === 0) return;
    if (e.key === "ArrowDown") {
      e.preventDefault();
      activeIndex = Math.min(activeIndex + 1, currentMatches.length - 1);
      renderSearchDropdown();
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      activeIndex = Math.max(activeIndex - 1, 0);
      renderSearchDropdown();
    } else if (e.key === "Enter") {
      e.preventDefault();
      const pick = currentMatches[activeIndex >= 0 ? activeIndex : 0];
      if (pick) selectAttacker(pick.speciesId);
    } else if (e.key === "Escape") {
      currentMatches = [];
      renderSearchDropdown();
    }
  });

  function renderSearchDropdown() {
    if (currentMatches.length === 0) {
      searchResultsEl.classList.add("hidden");
      searchResultsEl.innerHTML = "";
      return;
    }
    searchResultsEl.classList.remove("hidden");
    searchResultsEl.innerHTML = "";
    currentMatches.forEach((m, i) => {
      const li = document.createElement("li");
      li.textContent = m.speciesName;
      if (i === activeIndex) li.classList.add("active");
      li.addEventListener("mousedown", (e) => {
        e.preventDefault();
        selectAttacker(m.speciesId);
      });
      searchResultsEl.appendChild(li);
    });
  }

  function selectAttacker(speciesId) {
    const rec = BattleSim.recommendedMoveset(speciesId, state.activeLeague);
    currentAttacker = { speciesId, fastMove: rec.moveset[0], chargedMoves: rec.moveset.slice(1, 3) };
    searchInput.value = "";
    currentMatches = [];
    renderSearchDropdown();
    renderMatchupPanel();
    searchInput.focus();
  }

  function recomputeMatchupIfActive() {
    if (currentAttacker) renderMatchupPanel();
  }

  // ---------- voice search (hands-free) ----------

  const voiceBtn = document.getElementById("voice-toggle");
  const voiceStatusEl = document.getElementById("voice-status");

  function setVoiceStatus(text, isError) {
    voiceStatusEl.textContent = text || "";
    voiceStatusEl.classList.toggle("hidden", !text);
    voiceStatusEl.classList.toggle("error", !!isError);
  }

  if (!VoiceSearch.supported) {
    voiceBtn.classList.add("unsupported");
    voiceBtn.title = "Hands-free voice search needs Chrome or Edge";
  } else {
    const voiceController = VoiceSearch.createController({
      onInterim(text) {
        searchInput.value = text;
        handleSearchTextChanged();
      },
      onFinal(text) {
        const match = VoiceSearch.matchSpecies(text, POKEMON_LIST);
        if (match) {
          selectAttacker(match.speciesId);
          setVoiceStatus("🎤 Listening — heard “" + match.speciesName + "”");
        } else {
          searchInput.value = text;
          handleSearchTextChanged();
          setVoiceStatus("🎤 Didn't catch a Pokémon name in “" + text.trim() + "” — try again");
        }
      },
      onStatus(status) {
        if (status === "listening") {
          voiceBtn.classList.add("listening");
          voiceBtn.classList.remove("denied");
          voiceBtn.setAttribute("aria-pressed", "true");
          setVoiceStatus("🎤 Listening — say a Pokémon name");
        } else if (status === "stopped") {
          voiceBtn.classList.remove("listening");
          voiceBtn.setAttribute("aria-pressed", "false");
          setVoiceStatus("");
        } else if (status === "denied") {
          voiceBtn.classList.remove("listening");
          voiceBtn.classList.add("denied");
          voiceBtn.setAttribute("aria-pressed", "false");
          setVoiceStatus("Microphone access denied — allow it in your browser's site settings, then click the mic again.", true);
        } else if (status === "fatal") {
          voiceBtn.classList.remove("listening");
          voiceBtn.setAttribute("aria-pressed", "false");
          setVoiceStatus("Voice recognition stopped after repeated errors — click the mic to retry.", true);
        }
        // transient "error:*" statuses (e.g. a dropped network blip) are left
        // unsurfaced since onend will auto-restart the recognizer.
      },
    });

    voiceBtn.addEventListener("click", () => {
      if (voiceController.isListening) {
        voiceController.stop();
      } else {
        voiceController.start();
      }
    });
  }

  // ---------- matchup panel ----------

  const matchupPanelEl = document.getElementById("matchup-panel");
  let attackerMovesetEditorOpen = false;

  function renderMatchupPanel() {
    if (!currentAttacker) {
      matchupPanelEl.classList.add("hidden");
      matchupPanelEl.innerHTML = "";
      return;
    }
    const team = currentTeam().filter(Boolean);
    const attackerP = GM.pokemon[currentAttacker.speciesId];
    const attackerBattler = BattleSim.buildBattler(GM, currentAttacker.speciesId, state.activeLeague, {
      fastMove: currentAttacker.fastMove,
      chargedMoves: currentAttacker.chargedMoves,
    });

    matchupPanelEl.classList.remove("hidden");

    if (!attackerBattler) {
      matchupPanelEl.innerHTML = `<p class="hint">Couldn't build a battler for ${attackerP.speciesName}.</p>`;
      return;
    }

    if (team.length === 0) {
      matchupPanelEl.innerHTML = `<p class="hint">Add your 3 Pokémon for ${leagueLabel(state.activeLeague)} first (Edit Team, above).</p>`;
      return;
    }

    const pool = movePool(currentAttacker.speciesId);
    const results = team.map((slot) => {
      const defenderBattler = BattleSim.buildBattler(GM, slot.speciesId, state.activeLeague, {
        fastMove: slot.fastMove,
        chargedMoves: slot.chargedMoves,
      });
      const evalResult = BattleSim.evaluateMatchup(defenderBattler, attackerBattler);
      return { slot, defenderBattler, evalResult };
    });
    results.sort((a, b) => b.evalResult.avgScore - a.evalResult.avgScore);

    const header = document.createElement("div");
    header.className = "matchup-header";
    header.innerHTML = `
      <span class="attacker-name">${attackerP.speciesName}</span>
      <span class="attacker-moveset" id="attacker-moveset-label"></span>
      <button class="moveset-edit-toggle" id="moveset-edit-toggle">assume different moves</button>
    `;

    const editor = document.createElement("div");
    editor.className = "moveset-editor" + (attackerMovesetEditorOpen ? "" : " hidden");
    editor.id = "moveset-editor";
    editor.innerHTML = `
      <select data-role="fast"></select>
      <select data-role="charged1"></select>
      <select data-role="charged2"></select>
    `;
    fillMoveSelect(editor.querySelector('[data-role="fast"]'), pool.fast, currentAttacker.fastMove);
    fillMoveSelect(editor.querySelector('[data-role="charged1"]'), pool.charged, currentAttacker.chargedMoves[0], currentAttacker.chargedMoves[1]);
    fillMoveSelect(editor.querySelector('[data-role="charged2"]'), pool.charged, currentAttacker.chargedMoves[1], currentAttacker.chargedMoves[0], true);
    editor.querySelector('[data-role="fast"]').addEventListener("change", (e) => {
      currentAttacker.fastMove = e.target.value;
      renderMatchupPanel();
    });
    editor.querySelector('[data-role="charged1"]').addEventListener("change", (e) => {
      currentAttacker.chargedMoves[0] = e.target.value;
      renderMatchupPanel();
    });
    editor.querySelector('[data-role="charged2"]').addEventListener("change", (e) => {
      currentAttacker.chargedMoves[1] = e.target.value || null;
      renderMatchupPanel();
    });

    const cards = document.createElement("div");
    cards.className = "result-cards";
    results.forEach((r, i) => {
      cards.appendChild(renderResultCard(r, i === 0));
    });

    matchupPanelEl.innerHTML = "";
    matchupPanelEl.appendChild(header);
    matchupPanelEl.appendChild(editor);
    matchupPanelEl.appendChild(cards);

    matchupPanelEl.querySelector("#attacker-moveset-label").textContent =
      "assumed: " + moveName(currentAttacker.fastMove) + " / " +
      currentAttacker.chargedMoves.filter(Boolean).map(moveName).join(" / ");

    matchupPanelEl.querySelector("#moveset-edit-toggle").addEventListener("click", () => {
      attackerMovesetEditorOpen = !attackerMovesetEditorOpen;
      editor.classList.toggle("hidden", !attackerMovesetEditorOpen);
    });
  }

  function renderResultCard(r, isBest) {
    const p = GM.pokemon[r.slot.speciesId];
    const wins = r.evalResult.scenarios.filter((s) => s.winner === "defender").length;
    const losses = r.evalResult.scenarios.filter((s) => s.winner === "attacker").length;
    let verdictText, verdictClass;
    if (wins === 3) { verdictText = "WIN"; verdictClass = "win"; }
    else if (losses === 3) { verdictText = "LOSS"; verdictClass = "loss"; }
    else if (wins > losses) { verdictText = "LIKELY WIN"; verdictClass = "win"; }
    else if (losses > wins) { verdictText = "LIKELY LOSS"; verdictClass = "loss"; }
    else { verdictText = "TOSS-UP"; verdictClass = "mixed"; }

    const card = document.createElement("div");
    card.className = "result-card" + (isBest ? " best" : "");
    card.innerHTML = `
      <div class="rank-badge">${isBest ? "★" : "&nbsp;"}</div>
      <div>
        <div class="result-name">${p.speciesName}</div>
        <div class="result-sub">${moveName(r.slot.fastMove)} / ${r.slot.chargedMoves.filter(Boolean).map(moveName).join(" / ")}</div>
      </div>
      <div class="result-score">
        <div class="verdict ${verdictClass}">${verdictText}</div>
        <div class="shield-breakdown">
          ${r.evalResult.scenarios.map((s) => shieldChip(s)).join("")}
        </div>
      </div>
    `;
    return card;
  }

  function shieldChip(s) {
    const cls = s.winner === "defender" ? "win" : s.winner === "attacker" ? "loss" : "tie";
    const sign = s.score > 0 ? "+" : "";
    return `<span class="shield-chip ${cls}">${s.shields}sh ${sign}${Math.round(s.score)}%</span>`;
  }

  // ---------- top-level render ----------

  function renderAll() {
    renderLeagueSwitch();
    renderTeamPanel();

    const team = currentTeam().filter(Boolean);
    emptyStateEl.classList.toggle("hidden", team.length > 0);
    if (team.length === 0) {
      teamPanelEl.classList.remove("hidden");
    }

    currentAttacker = null;
    renderMatchupPanel();
  }

  renderAll();
})();
