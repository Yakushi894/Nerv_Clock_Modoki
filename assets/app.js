(function () {
  "use strict";

  var SEG = {
    "0": [1, 1, 1, 1, 1, 1, 0],
    "1": [0, 1, 1, 0, 0, 0, 0],
    "2": [1, 1, 0, 1, 1, 0, 1],
    "3": [1, 1, 1, 1, 0, 0, 1],
    "4": [0, 1, 1, 0, 0, 1, 1],
    "5": [1, 0, 1, 1, 0, 1, 1],
    "6": [1, 0, 1, 1, 1, 1, 1],
    "7": [1, 1, 1, 0, 0, 0, 0],
    "8": [1, 1, 1, 1, 1, 1, 1],
    "9": [1, 1, 1, 1, 0, 1, 1],
    "-": [0, 0, 0, 0, 0, 0, 1],
    " ": [0, 0, 0, 0, 0, 0, 0],
  };
  var NAMES = ["a", "b", "c", "d", "e", "f", "g"];
  var BOOT_MS = 2400;
  var STORAGE = "yashima-clock-php-v1";

  var state = {
    mode: "limit",
    durationMs: 300000,
    remainingMs: 300000,
    running: false,
    endAt: null,
    offsetMs: 0,
    ntp: null,
    ntpHost: "ntp.nict.jp",
    booting: true,
    bootStep: 1,
  };

  try {
    var saved = JSON.parse(localStorage.getItem(STORAGE) || "{}");
    if (saved.mode === "countdown") state.mode = "countdown";
    if (typeof saved.durationMs === "number") {
      state.durationMs = saved.durationMs;
      state.remainingMs = saved.durationMs;
    }
  } catch (e) {}

  function pad2(n) {
    return String(Math.max(0, Math.floor(n))).padStart(2, "0");
  }

  function splitHms(ms) {
    var total = Math.max(0, Math.floor(ms / 1000));
    return { h: pad2(Math.floor(total / 3600)), m: pad2(Math.floor((total % 3600) / 60)), s: pad2(total % 60) };
  }

  function grab(parts, type) {
    for (var i = 0; i < parts.length; i++) if (parts[i].type === type) return parts[i].value;
    return "00";
  }

  function partsFromDate(d, timeZone) {
    var t = new Intl.DateTimeFormat("ja-JP", {
      timeZone: timeZone,
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: false,
      hourCycle: "h23",
    }).formatToParts(d);
    var dp = new Intl.DateTimeFormat("ja-JP", {
      timeZone: timeZone,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      weekday: "short",
    }).formatToParts(d);
    return {
      h: grab(t, "hour"),
      m: grab(t, "minute"),
      s: grab(t, "second"),
      ymd: grab(dp, "year") + "." + grab(dp, "month") + "." + grab(dp, "day"),
      weekday: grab(dp, "weekday"),
    };
  }

  function gmtLabel(offsetMin) {
    var sign = offsetMin >= 0 ? "+" : "-";
    var abs = Math.abs(offsetMin);
    var h = Math.floor(abs / 60);
    var m = abs % 60;
    return m === 0 ? "UTC" + sign + h : "UTC" + sign + h + ":" + pad2(m);
  }

  function zoneName(d, tz, locale, form) {
    try {
      var parts = new Intl.DateTimeFormat(locale, { timeZone: tz, timeZoneName: form }).formatToParts(d);
      return grab(parts, "timeZoneName") || "";
    } catch (e) {
      return "";
    }
  }

  function localZone(d) {
    var tz = Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
    var offsetMin = -d.getTimezoneOffset();
    var isJapan = tz === "Asia/Tokyo" || tz === "Asia/Osaka" || tz === "Japan" || offsetMin === 540;
    if (isJapan) {
      return {
        timeZone: "Asia/Tokyo",
        titleJp: "日本標準時",
        titleSub: "日本標準時 (JST)",
        titleEn: "JST  UTC+9",
        footerLabel: "日本標準時 (JST)",
      };
    }
    var gmt = gmtLabel(offsetMin);
    var longJa = zoneName(d, tz, "ja-JP", "long");
    var shortEn = zoneName(d, tz, "en-US", "short") || gmt;
    var titleJp = longJa && !/GMT|UTC/i.test(longJa) ? longJa : "現地標準時";
    return {
      timeZone: tz,
      titleJp: titleJp,
      titleSub: titleJp + " (" + shortEn + ")",
      titleEn: shortEn + "  " + gmt,
      footerLabel: shortEn + " (" + gmt + ")",
    };
  }

  function digitHtml(ch) {
    var segs = SEG[ch] || SEG[" "];
    var html = '<span class="seg-digit" aria-hidden="true">';
    for (var i = 0; i < 7; i++) {
      html += '<span class="seg seg-' + NAMES[i] + (segs[i] ? " on" : "") + '"></span>';
    }
    return html + "</span>";
  }

  function pairHtml(val) {
    return '<span class="digit-pair">' + digitHtml(val[0] || "0") + digitHtml(val[1] || "0") + "</span>";
  }

  function colonHtml() {
    return '<span class="seg-colon" aria-hidden="true"><span></span><span></span></span>';
  }

  function hmsHtml(h, m, s, cls) {
    return '<div class="' + cls + '">' + pairHtml(h) + colonHtml() + pairHtml(m) + colonHtml() + pairHtml(s) + "</div>";
  }

  function formatOffset(ms) {
    var n = Math.round(ms);
    return (n >= 0 ? "+" : "") + n + "ms";
  }

  function setLamp(el, ok) {
    el.className = "ntp-lamp " + (ok == null ? "is-wait" : ok ? "is-ok" : "is-fail");
  }

  function persist() {
    try {
      localStorage.setItem(STORAGE, JSON.stringify({ mode: state.mode, durationMs: state.durationMs }));
    } catch (e) {}
  }

  function nowDate() {
    return new Date(Date.now() + state.offsetMs);
  }

  function fitBox(parent, inner) {
    if (!parent || !inner || parent.hidden) {
      if (inner) inner.style.transform = "none";
      return;
    }
    inner.style.transform = "none";
    var pw = parent.clientWidth;
    var ph = parent.clientHeight;
    var rw = Math.max(inner.scrollWidth, inner.offsetWidth);
    var rh = Math.max(inner.scrollHeight, inner.offsetHeight);
    if (pw < 8 || ph < 8 || rw < 8 || rh < 8) return;
    var s = Math.min(pw / rw, ph / rh) * 0.88;
    inner.style.transformOrigin = "center center";
    inner.style.transform = "scale(" + Math.max(0.35, Math.min(s, 18)) + ")";
  }

  function fitStage() {
    fitBox(document.querySelector(".eva-stage-head"), document.getElementById("headFit"));
    fitBox(document.getElementById("timer"), document.getElementById("timerFit"));
    fitBox(document.getElementById("utcRow"), document.getElementById("utcFit"));
  }

  function renderBoot() {
    var ntp = state.ntp;
    var magi = [
      state.bootStep >= 2 ? "OK" : "....",
      state.bootStep >= 3 ? "OK" : "....",
      state.bootStep >= 4 ? "OK" : "....",
    ];
    var ntpLine = !ntp
      ? "NTP QUERY      " + state.ntpHost
      : ntp.ok
        ? "NTP SYNC       " + ntp.server + "  " + formatOffset(ntp.offsetMs)
        : "NTP FAIL       " + state.ntpHost + "  LOCAL";
    var lines = [
      "MAGI SYSTEM  BOOT SEQUENCE",
      "MELCHIOR-1     " + magi[0],
      "BALTHASAR-2    " + magi[1],
      "CASPER-3       " + magi[2],
      ntpLine,
      state.bootStep >= 6 ? "LINK ESTABLISHED" : "LINK PENDING",
    ];
    var shown = Math.min(lines.length, Math.max(1, state.bootStep));
    var ul = document.getElementById("bootLines");
    ul.innerHTML = lines
      .slice(0, shown)
      .map(function (l) {
        return "<li>" + l + "</li>";
      })
      .join("");
    var label = !ntp ? "NTP QUERY" : ntp.ok ? "NTP SYNC" : "LOCAL CLOCK";
    document.getElementById("bootNtp").textContent = label;
    setLamp(document.getElementById("bootLamp"), ntp ? ntp.ok : null);
  }

  function renderClock() {
    var d = nowDate();
    var zone = localZone(d);
    var local = partsFromDate(d, zone.timeZone);
    var utc = partsFromDate(d, "UTC");
    var show = state.mode === "countdown" ? splitHms(state.remainingMs) : local;
    var danger = state.mode === "countdown" && state.remainingMs <= 10000;
    var root = document.getElementById("root");
    root.classList.toggle("is-danger", danger);
    root.setAttribute("data-mode", state.mode);

    document.getElementById("kicker").textContent = state.mode === "countdown" ? "活動限界まで" : zone.titleJp;
    document.getElementById("subJp").textContent = state.mode === "countdown" ? "COUNTDOWN" : zone.titleSub;
    document.getElementById("subEn").textContent = state.mode === "countdown" ? "ACTIVITY LIMIT" : zone.titleEn;
    document.getElementById("timerFit").innerHTML = hmsHtml(show.h, show.m, show.s, "stacked-hms");
    document.getElementById("timer").setAttribute("aria-label", show.h + ":" + show.m + ":" + show.s);
    document.getElementById("utcRow").hidden = state.mode !== "limit";
    if (state.mode === "limit") {
      document.getElementById("utcTimer").innerHTML =
        pairHtml(utc.h) + colonHtml() + pairHtml(utc.m) + colonHtml() + pairHtml(utc.s);
    }

    document.getElementById("headDate").textContent = local.ymd + "  " + local.weekday;
    document.getElementById("footLocalLabel").textContent = zone.footerLabel;
    document.getElementById("footLocal").textContent = local.h + ":" + local.m + ":" + local.s;
    document.getElementById("footUtc").textContent = utc.h + ":" + utc.m + ":" + utc.s;

    var ntp = state.ntp;
    var ok = ntp ? ntp.ok : null;
    var ntpText = !ntp ? "NTP QUERY" : ok ? "NTP SYNC" : "LOCAL CLOCK";
    var ntpVal = ok ? ntp.server + "  " + formatOffset(ntp.offsetMs) : "端末時刻";
    document.getElementById("headNtp").textContent = ntpText;
    document.getElementById("footNtpLabel").textContent = "NTP " + (ok ? "SYNC" : ntp ? "LOCAL" : "QUERY");
    document.getElementById("footNtpVal").textContent = ntpVal;
    setLamp(document.getElementById("headLamp"), ok);
    setLamp(document.getElementById("footLamp"), ok);
    var stat = document.getElementById("footNtpStat");
    stat.classList.toggle("is-ok", ok === true);
    stat.classList.toggle("is-fail", ok === false);

    document.querySelectorAll("[data-mode]").forEach(function (btn) {
      var on = btn.getAttribute("data-mode") === state.mode;
      btn.classList.toggle("is-on", on);
      btn.setAttribute("aria-selected", on ? "true" : "false");
    });
    document.getElementById("countTools").hidden = state.mode !== "countdown";
    document.querySelectorAll("[data-ms]").forEach(function (btn) {
      btn.classList.toggle("is-on", Number(btn.getAttribute("data-ms")) === state.durationMs);
    });
    var tog = document.getElementById("btnToggle");
    tog.textContent = state.running
      ? "停止"
      : state.remainingMs > 0 && state.remainingMs < state.durationMs
        ? "再開"
        : "開始";
  }

  function tick() {
    if (state.running && state.endAt != null) {
      state.remainingMs = Math.max(0, state.endAt - Date.now());
      if (state.remainingMs <= 0) {
        state.running = false;
        state.endAt = null;
      }
    }
    if (!state.booting) renderClock();
  }

  function startCountdown() {
    var base = state.running ? state.remainingMs : state.remainingMs > 0 ? state.remainingMs : state.durationMs;
    state.endAt = Date.now() + base;
    state.running = true;
    state.mode = "countdown";
    persist();
    renderClock();
    window.requestAnimationFrame(fitStage);
  }

  function pauseCountdown() {
    if (state.endAt != null) state.remainingMs = Math.max(0, state.endAt - Date.now());
    state.endAt = null;
    state.running = false;
    renderClock();
  }

  function resetCountdown(ms) {
    state.endAt = null;
    state.running = false;
    state.remainingMs = ms;
    state.durationMs = ms;
    persist();
    renderClock();
  }

  function setMode(mode) {
    state.mode = mode;
    persist();
    renderClock();
    window.requestAnimationFrame(fitStage);
  }

  function boot() {
    var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var steps = reduce ? [80, 160, 240, 320, 400, 480] : [380, 760, 1140, 1520, 1900, 2280];
    var started = Date.now();
    renderBoot();
    steps.forEach(function (t, i) {
      setTimeout(function () {
        state.bootStep = i + 2;
        renderBoot();
      }, t);
    });

    var ntpDone = fetch("ntp.php", { cache: "no-store" })
      .then(function (r) {
        return r.json();
      })
      .then(function (result) {
        state.ntpHost = result.server || state.ntpHost;
        if (result.ok && Number.isFinite(Number(result.unixMs))) {
          var applied = Number(result.unixMs) - Date.now();
          state.ntp = { ok: true, server: result.server, offsetMs: applied, unixMs: result.unixMs };
          state.offsetMs = applied;
        } else {
          state.ntp = { ok: false, server: state.ntpHost, error: result.error || "unreachable" };
          state.offsetMs = 0;
        }
        renderBoot();
      })
      .catch(function () {
        state.ntp = { ok: false, server: state.ntpHost, error: "unreachable" };
        state.offsetMs = 0;
        renderBoot();
      });

    ntpDone.finally(function () {
      var wait = Math.max(0, (reduce ? 500 : BOOT_MS) - (Date.now() - started));
      setTimeout(function () {
        state.booting = false;
        document.getElementById("boot").hidden = true;
        document.getElementById("app").hidden = false;
        document.getElementById("root").classList.remove("is-booting");
        renderClock();
        window.requestAnimationFrame(fitStage);
      }, wait);
    });
  }

  document.querySelectorAll("[data-mode]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      setMode(btn.getAttribute("data-mode"));
    });
  });
  document.querySelectorAll("[data-ms]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      resetCountdown(Number(btn.getAttribute("data-ms")));
    });
  });
  document.getElementById("btnToggle").addEventListener("click", function () {
    if (state.running) pauseCountdown();
    else startCountdown();
  });
  document.getElementById("btnReset").addEventListener("click", function () {
    resetCountdown(state.durationMs);
  });
  document.getElementById("btnFull").addEventListener("click", function () {
    if (!document.fullscreenElement) document.documentElement.requestFullscreen().catch(function () {});
    else document.exitFullscreen().catch(function () {});
  });

  window.addEventListener("keydown", function (e) {
    if (e.key === "f" || e.key === "F") document.getElementById("btnFull").click();
    if (e.key === " ") {
      e.preventDefault();
      if (state.mode !== "countdown") setMode("countdown");
      if (state.running) pauseCountdown();
      else startCountdown();
    }
    if (e.key === "1") setMode("limit");
    if (e.key === "2") setMode("countdown");
  });

  window.addEventListener("resize", function () {
    window.requestAnimationFrame(fitStage);
  });
  if (typeof ResizeObserver !== "undefined") {
    var ro = new ResizeObserver(function () {
      window.requestAnimationFrame(fitStage);
    });
    var stage = document.querySelector(".eva-stage");
    if (stage) ro.observe(stage);
  }

  setInterval(tick, 200);
  boot();
})();
