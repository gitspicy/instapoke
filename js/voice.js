// Hands-free voice search: wraps the Web Speech API (Chrome/Edge) for
// continuous listening, plus a tolerant fuzzy matcher for turning a spoken
// (and possibly mis-heard) phrase into a Pokemon species pick.
(function (global) {
  "use strict";

  const SpeechRecognitionImpl = global.SpeechRecognition || global.webkitSpeechRecognition;
  const MAX_CONSECUTIVE_ERRORS = 5;

  function normalize(text) {
    return text.toLowerCase().replace(/[^a-z0-9 ]/g, "").replace(/\s+/g, " ").trim();
  }

  function levenshtein(a, b) {
    const m = a.length, n = b.length;
    if (m === 0) return n;
    if (n === 0) return m;
    let prev = new Array(n + 1);
    let curr = new Array(n + 1);
    for (let j = 0; j <= n; j++) prev[j] = j;
    for (let i = 1; i <= m; i++) {
      curr[0] = i;
      for (let j = 1; j <= n; j++) {
        const cost = a[i - 1] === b[j - 1] ? 0 : 1;
        curr[j] = Math.min(curr[j - 1] + 1, prev[j] + 1, prev[j - 1] + cost);
      }
      [prev, curr] = [curr, prev];
    }
    return prev[n];
  }

  // Turn a spoken transcript into a best-guess Pokemon pick.
  // `list` is an array of { speciesId, speciesName }.
  // Returns { speciesId, speciesName, confidence: 'exact'|'close'|'fuzzy' } or null.
  function matchSpecies(transcript, list) {
    const spoken = normalize(transcript);
    // Guards against filler words ("um", "uh", "the") from triggering a match
    // — the shortest real Pokemon species name (Mew) is 3 letters.
    if (spoken.replace(/ /g, "").length < 3) return null;
    const spokenJoined = spoken.replace(/ /g, "");

    let best = null;
    let bestScore = Infinity;
    let bestConfidence = null;

    for (const p of list) {
      const name = normalize(p.speciesName);
      const nameJoined = name.replace(/ /g, "");

      if (name === spoken || nameJoined === spokenJoined) {
        return { speciesId: p.speciesId, speciesName: p.speciesName, confidence: "exact" };
      }
      if (name.startsWith(spoken) || nameJoined.startsWith(spokenJoined)) {
        // Prefer the shortest name that starts with what was said (most likely match).
        if (name.length < bestScore || bestConfidence !== "close") {
          best = p;
          bestScore = name.length;
          bestConfidence = "close";
        }
        continue;
      }
      if (bestConfidence === "close") continue;

      const dist = Math.min(levenshtein(spoken, name), levenshtein(spokenJoined, nameJoined));
      const threshold = Math.max(2, Math.floor(Math.max(name.length, spoken.length) * 0.3));
      if (dist <= threshold && dist < bestScore) {
        best = p;
        bestScore = dist;
        bestConfidence = "fuzzy";
      }
    }

    if (!best) return null;
    return { speciesId: best.speciesId, speciesName: best.speciesName, confidence: bestConfidence };
  }

  function createController({ onInterim, onFinal, onStatus }) {
    let recognition = null;
    let wantListening = false;
    let consecutiveErrors = 0;

    function buildRecognition() {
      const r = new SpeechRecognitionImpl();
      r.continuous = true;
      r.interimResults = true;
      r.lang = "en-US";

      r.onstart = () => {
        consecutiveErrors = 0;
        onStatus && onStatus("listening");
      };

      r.onresult = (event) => {
        let interim = "";
        for (let i = event.resultIndex; i < event.results.length; i++) {
          const result = event.results[i];
          const transcript = result[0].transcript;
          if (result.isFinal) {
            onFinal && onFinal(transcript);
          } else {
            interim += transcript;
          }
        }
        if (interim) onInterim && onInterim(interim);
      };

      r.onerror = (event) => {
        if (event.error === "not-allowed" || event.error === "service-not-allowed") {
          wantListening = false;
          onStatus && onStatus("denied");
          return;
        }
        if (event.error === "no-speech" || event.error === "aborted") {
          return; // benign, onend will decide whether to restart
        }
        consecutiveErrors++;
        onStatus && onStatus("error:" + event.error);
        if (consecutiveErrors >= MAX_CONSECUTIVE_ERRORS) {
          wantListening = false;
          onStatus && onStatus("fatal");
        }
      };

      r.onend = () => {
        if (wantListening && consecutiveErrors < MAX_CONSECUTIVE_ERRORS) {
          try {
            recognition = buildRecognition();
            recognition.start();
          } catch (e) {
            onStatus && onStatus("fatal");
          }
        } else {
          onStatus && onStatus("stopped");
        }
      };

      return r;
    }

    return {
      start() {
        if (!SpeechRecognitionImpl) {
          onStatus && onStatus("unsupported");
          return;
        }
        wantListening = true;
        consecutiveErrors = 0;
        recognition = buildRecognition();
        try {
          recognition.start();
        } catch (e) {
          onStatus && onStatus("fatal");
        }
      },
      stop() {
        wantListening = false;
        if (recognition) recognition.stop();
      },
      get isListening() {
        return wantListening;
      },
    };
  }

  global.VoiceSearch = {
    supported: !!SpeechRecognitionImpl,
    matchSpecies,
    createController,
  };
})(window);
