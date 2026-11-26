// ===== Inc 162: aplikasi terpasang (PWA) & isian tanpa sinyal — Android dan iPhone =====
// Antrean isian di HP: setiap isian yang gagal terkirim (tanpa sinyal / sesi habis) disimpan di
// localStorage per pengguna, ditampilkan di layar, dan dikirim ulang otomatis saat sinyal kembali.
window.SusenasOutbox = (function () {
  var userMeta = document.querySelector('meta[name="app-user"]');
  var KEY = "susenas-outbox-" + (userMeta ? userMeta.content : "anon");
  var REJ = KEY + "-ditolak";
  var CSRF = function () { return (document.querySelector('meta[name="csrf-token"]') || {}).content || ""; };
  var flushing = false, needReload = false;

  function load(k) { try { return JSON.parse(localStorage.getItem(k) || "{}"); } catch (e) { return {}; } }
  function save(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function items() { var o = load(KEY); return Object.keys(o).map(function (k) { return o[k]; }); }

  function put(docId, qid, art, value, label) {
    var o = load(KEY);
    o[docId + ":" + qid + ":" + (art || 0)] = { doc: String(docId), qid: String(qid), art: String(art || 0),
      value: value, label: label || "", at: Date.now() };
    save(KEY, o);
    paint();
  }
  function forDoc(docId) { return items().filter(function (it) { return it.doc === String(docId); }); }
  function drop(it) { var o = load(KEY); delete o[it.doc + ":" + it.qid + ":" + it.art]; save(KEY, o); }

  function send(it) {
    var body = new URLSearchParams({ question_id: it.qid, value: it.value, art_index: it.art });
    return fetch("/entri/" + it.doc + "/jawaban", {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-TOKEN": CSRF(),
        "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
      body: body.toString(),
    });
  }

  function flush() {
    if (flushing || !navigator.onLine) return Promise.resolve();
    var queue = items().sort(function (a, b) { return a.at - b.at; });
    if (!queue.length) return Promise.resolve();
    flushing = true;
    var i = 0;
    function next() {
      if (i >= queue.length) return Promise.resolve();
      var it = queue[i++];
      return send(it).then(function (r) {
        if (r.status === 419 || r.status === 401 || r.redirected) { needReload = true; throw new Error("sesi"); }
        if (r.ok) {
          drop(it);
          return r.json().then(function (d) {
            document.dispatchEvent(new CustomEvent("susenas:sent", { detail: { doc: it.doc, state: d.state } }));
          }).then(next);
        }
        // ditolak server (periode ditutup, isian sudah bersih, dsb.) — simpan sebagai catatan, jangan diulang
        var rej = load(REJ);
        rej[it.doc + ":" + it.qid + ":" + it.art] = Object.assign({ status: r.status }, it);
        save(REJ, rej);
        drop(it);
        return next();
      });
    }
    return next().catch(function () {}).then(function () { flushing = false; paint(); });
  }

  // ---- pita status koneksi + antrean (bawah layar)
  var bar = null;
  function paint() {
    if (!document.body) return;
    var n = items().length, rej = Object.keys(load(REJ)).length, off = !navigator.onLine;
    if (!bar) {
      bar = document.createElement("div");
      bar.className = "net-bar";
      bar.setAttribute("role", "status");
      document.body.appendChild(bar);
    }
    var parts = [];
    if (off) parts.push("<strong>Tanpa sinyal.</strong> Isian tetap tersimpan di HP ini.");
    if (n) parts.push(n + " isian menunggu dikirim" + (off ? "." : " — mengirim…"));
    if (needReload && n) parts.push('Sesi masuk berakhir. <a href="' + location.pathname + '">Muat ulang halaman</a> (masuk lagi bila diminta) — isian tidak hilang.');
    if (rej) parts.push(rej + ' isian ditolak server (mis. periode lapangan sudah ditutup). <button type="button" class="btn-ghost" data-rej-show>Lihat</button>');
    bar.innerHTML = parts.join(" ");
    bar.hidden = !parts.length;
    bar.classList.toggle("off", off);
    var b = bar.querySelector("[data-rej-show]");
    if (b) b.addEventListener("click", function () {
      var all = load(REJ);
      var list = Object.keys(all).map(function (k) { var r = all[k];
        return "Sampel #" + r.doc + " · " + (r.label || ("rincian " + r.qid)) + (r.art !== "0" ? " baris " + r.art : "") + " = " + r.value; });
      if (confirm("Isian yang ditolak server:\n\n" + list.join("\n") + "\n\nCatat bila perlu, lalu tekan OK untuk menghapus daftar ini.")) {
        save(REJ, {}); paint();
      }
    });
  }

  window.addEventListener("online", function () { paint(); flush(); });
  window.addEventListener("offline", paint);
  document.addEventListener("visibilitychange", function () { if (!document.hidden) flush(); });
  setInterval(flush, 20000);
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", function () { paint(); flush(); });
  else { paint(); flush(); }

  return { put: put, forDoc: forDoc, flush: flush, count: function () { return items().length; }, paint: paint };
})();

// Daftarkan service worker + ajakan pasang aplikasi (Android: tombol; iPhone: petunjuk Bagikan → Tambah ke Layar Utama)
(function () {
  if ("serviceWorker" in navigator && (location.protocol === "https:" || location.hostname === "localhost" || location.hostname === "127.0.0.1")) {
    navigator.serviceWorker.register("/sw.js").catch(function () {});
    if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(function () {});
  }
  var standalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  var DISMISS = "susenas-install-dismissed";
  var dismissed = function () { try { return localStorage.getItem(DISMISS) === "1"; } catch (e) { return false; } };
  if (standalone || !document.querySelector('meta[name="app-user"]')) return;

  function banner(html, onInstall) {
    if (dismissed() || document.querySelector(".install-bar")) return;
    var el = document.createElement("div");
    el.className = "install-bar";
    el.innerHTML = '<img src="/icons/icon-192.png" alt="" width="36" height="36"><div class="install-text">' + html + "</div>"
      + (onInstall ? '<button type="button" data-install>Pasang</button>' : "")
      + '<button type="button" class="btn-ghost" data-close>Nanti</button>';
    document.body.appendChild(el);
    el.querySelector("[data-close]").addEventListener("click", function () {
      try { localStorage.setItem(DISMISS, "1"); } catch (e) {}
      el.remove();
    });
    if (onInstall) el.querySelector("[data-install]").addEventListener("click", function () { onInstall(el); });
  }

  // Android (Chrome / Edge / Samsung Internet)
  window.addEventListener("beforeinstallprompt", function (e) {
    e.preventDefault();
    var prompt = e;
    banner("<strong>Pasang Sistem SUSENAS di HP</strong><br>Buka langsung dari layar utama, layar penuh, dan tetap bisa mengisi saat sinyal hilang.",
      function (el) { prompt.prompt(); prompt.userChoice.finally(function () { el.remove(); }); });
  });

  // iPhone / iPad — Safari tidak punya tombol pasang otomatis, jadi beri petunjuk
  var ua = navigator.userAgent;
  var ios = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  var go = function () {
    if (ios && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua)) {
      banner("<strong>Pasang di iPhone:</strong> ketuk tombol <strong>Bagikan</strong> "
        + "(kotak dengan panah ke atas) lalu pilih <strong>Tambah ke Layar Utama</strong>.");
    } else if (ios) {
      banner("<strong>Pasang di iPhone:</strong> buka alamat ini di <strong>Safari</strong>, ketuk <strong>Bagikan</strong>, lalu <strong>Tambah ke Layar Utama</strong>.");
    }
  };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", go); else go();
})();

// Keluar akun: peringatkan bila masih ada isian belum terkirim; hapus salinan halaman di HP.
// Formulir POST saat tanpa sinyal: tahan dengan pesan (bukan halaman galat peramban).
(function () {
  document.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest('a[href$="/logout"]');
    if (!a) return;
    var n = window.SusenasOutbox ? window.SusenasOutbox.count() : 0;
    if (n && !confirm(n + " isian belum terkirim dan masih tersimpan di HP ini. Isian itu akan dikirim saat Anda masuk lagi dengan akun yang sama. Tetap keluar?")) {
      e.preventDefault();
      return;
    }
    if (navigator.serviceWorker && navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage("clear-pages");
  });
  document.addEventListener("submit", function (e) {
    var f = e.target;
    if (navigator.onLine || (f.getAttribute("method") || "").toLowerCase() !== "post") return;
    e.preventDefault();
    alert("Tidak ada sinyal. Tindakan ini perlu sinyal — coba lagi setelah sinyal kembali. Isian yang sudah diketik tetap tersimpan di HP.");
  }, true);
})();

// Tugas Saya: simpan halaman entri sampel ke HP supaya bisa dibuka tanpa sinyal
(function () {
  var btn = document.getElementById("prep-offline");
  if (!btn) return;
  var out = document.getElementById("prep-offline-out");
  if (!("caches" in window) || !navigator.serviceWorker) {
    btn.disabled = true;
    out.textContent = "Belum tersedia di sini — perlu alamat https (server resmi).";
    return;
  }
  btn.addEventListener("click", function () {
    var urls = JSON.parse(btn.dataset.urls || "[]");
    var done = 0;
    btn.disabled = true;
    out.textContent = "menyimpan 0 dari " + urls.length + "…";
    caches.open("susenas-pages-v1").then(function (c) {
      return urls.reduce(function (p, u) {
        return p.then(function () {
          return fetch(u, { credentials: "same-origin" }).then(function (r) {
            if (r.ok && !r.redirected) return c.put(new URL(u, location.href).pathname, r);
          }).catch(function () {}).then(function () { out.textContent = "menyimpan " + (++done) + " dari " + urls.length + "…"; });
        });
      }, Promise.resolve());
    }).then(function () {
      out.textContent = "✓ " + urls.length + " sampel siap dibuka tanpa sinyal (disimpan pukul "
        + new Date().toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" }) + ").";
      btn.disabled = false;
    });
  });
})();

// Tombol tema: sistem -> terang -> gelap -> sistem
(function () {
  var KEY = "susenas-theme";
  var LABELS = { system: "Tema: Sistem", light: "Tema: Terang", dark: "Tema: Gelap" };
  var ORDER = ["system", "light", "dark"];

  function current() {
    try {
      var t = localStorage.getItem(KEY);
      return t === "light" || t === "dark" ? t : "system";
    } catch (e) { return "system"; }
  }
  function apply(mode) {
    if (mode === "system") delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = mode;
    document.querySelectorAll("[data-theme-label]").forEach(function (el) {
      el.textContent = LABELS[mode];
    });
  }
  apply(current());
  document.querySelectorAll("[data-theme-toggle]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var next = ORDER[(ORDER.indexOf(current()) + 1) % ORDER.length];
      try {
        if (next === "system") localStorage.removeItem(KEY);
        else localStorage.setItem(KEY, next);
      } catch (e) {}
      apply(next);
    });
  });
})();

// Lipat/buka blok + navigasi blok pada layar entri.
(function () {
  var main = document.querySelector(".entry-main");
  if (!main) return;
  var KEY = "susenas-collapsed-blocks";
  var collapsed = {};
  try { collapsed = JSON.parse(localStorage.getItem(KEY) || "{}") || {}; } catch (e) {}

  function persist() {
    try { localStorage.setItem(KEY, JSON.stringify(collapsed)); } catch (e) {}
  }

  main.querySelectorAll(".block").forEach(function (sec, idx) {
    var code = sec.dataset.block;
    // belum pernah disentuh user -> default cuma blok pertama yang terbuka (kesan "satu blok
    // sekali layar", bukan tumpukan panjang), tapi tetap bentuk accordion lama, bukan wizard kaku.
    var shouldCollapse = (code in collapsed) ? collapsed[code] : idx !== 0;
    if (shouldCollapse) sec.classList.add("collapsed");
    var head = sec.querySelector("[data-block-toggle]");
    if (head) head.addEventListener("click", function (e) {
      if (e.target.tagName === "A") return;
      sec.classList.toggle("collapsed");
      collapsed[code] = sec.classList.contains("collapsed");
      persist();
    });
  });

  // datang lewat tautan ke blok tertentu (mis. tombol "Pratinjau" Blok IV.1 di Metadata → #blok-IV.1):
  // blok itu dibuka & digulir ke sana — sebelumnya tetap terlipat sehingga pratinjau tampak "tertutup"
  if (location.hash.length > 1) {
    var target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
    var targetBlock = target && target.closest(".block");
    if (targetBlock) {
      targetBlock.classList.remove("collapsed");
      setTimeout(function () {
        targetBlock.scrollIntoView({ block: "start" });
        if (getComputedStyle(document.body).overflowY === "hidden") window.scrollTo(0, 0);
      }, 0);
    }
  }

  // klik navigasi: pastikan blok terbuka lalu scroll
  document.querySelectorAll("[data-nav-block]").forEach(function (a) {
    a.addEventListener("click", function () {
      var sec = main.querySelector('.block[data-block="' + a.dataset.navBlock + '"]');
      if (sec) { sec.classList.remove("collapsed"); collapsed[a.dataset.navBlock] = false; persist(); }
    });
  });

  // tombol "blok sebelumnya/berikutnya": tutup blok ini, buka & gulir ke blok tujuan
  main.querySelectorAll("[data-step-prev], [data-step-next]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var curSec = btn.closest(".block");
      var targetCode = btn.dataset.stepNext || btn.dataset.stepPrev;
      var targetSec = main.querySelector('.block[data-block="' + targetCode + '"]');
      if (curSec) { curSec.classList.add("collapsed"); collapsed[curSec.dataset.block] = true; }
      if (targetSec) { targetSec.classList.remove("collapsed"); collapsed[targetCode] = false; }
      persist();
      if (targetSec) targetSec.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  });

  // sorot blok yang sedang tampak
  var navLinks = {};
  document.querySelectorAll("[data-nav-block]").forEach(function (a) { navLinks[a.dataset.navBlock] = a; });
  if ("IntersectionObserver" in window) {
    var obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        var a = navLinks[en.target.dataset.block];
        if (a && en.isIntersecting) {
          Object.values(navLinks).forEach(function (x) { x.classList.remove("on"); });
          a.classList.add("on");
        }
      });
    }, { rootMargin: "-40% 0px -55% 0px" });
    main.querySelectorAll(".block").forEach(function (s) { obs.observe(s); });
  }
})();

// Filter pencarian + "hanya error/kosong" lintas blok (berlaku untuk semua peran, termasuk viewer).
(function () {
  var main = document.querySelector(".entry-main");
  var text = document.getElementById("entry-filter-text");
  var issuesOnly = document.getElementById("entry-filter-issues");
  if (!main || !text) return;

  function norm(s) { return (s || "").toLowerCase(); }
  function rowLabel(tr) {
    var th = tr.querySelector("th");
    return norm(th ? th.textContent : tr.textContent);
  }
  function fieldLabel(f) {
    var lab = f.querySelector(".q-label");
    return norm(lab ? lab.textContent : f.textContent);
  }
  function isEmptyEl(el) {
    var inputs = el.querySelectorAll(".answer");
    if (!inputs.length) return false;
    return Array.prototype.every.call(inputs, function (i) { return !i.value; });
  }
  function hasIssue(el) {
    return el.classList.contains("has-error") || el.classList.contains("has-warning")
      || el.classList.contains("row-has-warn") || !!el.querySelector(".has-error, .has-warning");
  }

  function apply() {
    var q = norm(text.value).trim();
    var onlyIssues = !!(issuesOnly && issuesOnly.checked);
    var active = !!q || onlyIssues;
    main.querySelectorAll(".block").forEach(function (block) {
      var anyVisible = false;
      block.querySelectorAll(".field").forEach(function (f) {
        var match = (!q || fieldLabel(f).indexOf(q) !== -1) && (!onlyIssues || hasIssue(f) || isEmptyEl(f));
        f.classList.toggle("is-filtered-out", active && !match);
        if (match) anyVisible = true;
      });
      block.querySelectorAll(".roster tbody tr").forEach(function (tr) {
        if (tr.classList.contains("group-header") || tr.classList.contains("row-warn")) return;
        var match = (!q || rowLabel(tr).indexOf(q) !== -1) && (!onlyIssues || hasIssue(tr) || isEmptyEl(tr));
        tr.classList.toggle("is-filtered-out", active && !match);
        if (match) anyVisible = true;
      });
      block.classList.toggle("is-filter-empty", active && !anyVisible);
      if (active && anyVisible) block.classList.remove("collapsed");
    });
  }
  text.addEventListener("input", apply);
  if (issuesOnly) issuesOnly.addEventListener("change", apply);
})();

// Autosave + validasi langsung untuk layar entri (blok ruta & roster ART).
(function () {
  var root = document.querySelector(".entry-main");
  if (!root) return;
  var docId = root.dataset.doc;
  var canEdit = root.dataset.canEdit === "1";
  if (!canEdit) return;

  var savedNote = document.getElementById("saved-note");
  var cntErr = document.getElementById("cnt-err");
  var cntWarn = document.getElementById("cnt-warn");
  var docFlags = document.getElementById("doc-flags");
  var derivedTbl = document.getElementById("derived");
  var rekapTbl = document.getElementById("rekap");
  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";

  function post(qid, value, artIndex) {
    var body = new URLSearchParams();
    body.set("question_id", qid);
    body.set("value", value);
    body.set("art_index", artIndex || 0);
    return fetch("/entri/" + docId + "/jawaban", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
        "X-CSRF-TOKEN": CSRF,
        "X-Requested-With": "XMLHttpRequest",
      },
      body: body.toString(),
    }).then(function (r) {
      if (!r.ok) throw new Error("gagal simpan (" + r.status + ")");
      return r.json();
    });
  }

  function flash(msg, ok) {
    savedNote.textContent = msg;
    savedNote.style.color = ok ? "" : "var(--err)";
    if (ok && msg) setTimeout(function () { savedNote.textContent = ""; }, 1500);
  }

  var progRoot = document.getElementById("entry-progress");
  function paintProgress(p) {
    if (!progRoot || !p) return;
    var q = function (s) { return progRoot.querySelector(s); };
    q("[data-prog-pct]").textContent = p.pct;
    q("[data-prog-filled]").textContent = p.filled;
    q("[data-prog-total]").textContent = p.expected;
    q("[data-prog-fill]").style.width = p.pct + "%";
    var det = q(".prog-missing");
    q("[data-prog-missing-n]").textContent = p.missing_total;
    det.hidden = p.missing_total === 0;
    var ul = q("[data-prog-missing-list]");
    ul.innerHTML = "";
    (p.missing || []).forEach(function (m) {
      var li = document.createElement("li");
      var a = document.createElement("a");
      a.href = "#blok-" + m.block;
      a.innerHTML = '<span class="mono"></span> ';
      a.querySelector("span").textContent = m.code;
      a.appendChild(document.createTextNode(m.label));
      li.appendChild(a);
      ul.appendChild(li);
    });
  }

  function collectByVar(state) {
    var byVar = {};
    state.blocks.forEach(function (b) {
      (b.questions || []).forEach(function (q) { byVar[q.var] = q; });
      (b.columns || []).forEach(function (c) { byVar[c.var] = c; });
    });
    return byVar;
  }

  function repaint(state) {
    cntErr.textContent = state.n_err;
    cntWarn.textContent = state.n_warn;
    paintProgress(state.progress);
    var byVar = collectByVar(state);

    // badge per blok (navigasi + judul blok)
    (state.blocks || []).forEach(function (b) {
      var li = document.querySelector('[data-nav-block="' + b.code + '"]');
      if (li && li.parentNode) {
        li.parentNode.querySelectorAll(".pill").forEach(function (p) { p.remove(); });
        [["err", b.n_err], ["warn", b.n_warn]].forEach(function (pair) {
          if (!pair[1]) return;
          var s = document.createElement("span");
          s.className = "pill " + pair[0];
          s.textContent = pair[1];
          li.parentNode.appendChild(s);
        });
      }
    });

    // blok ruta: satu field per rincian
    root.querySelectorAll(".field").forEach(function (f) {
      var q = byVar[f.dataset.var];
      f.classList.remove("has-error", "has-warning", "is-skipped");
      var box = f.querySelector(".q-flags");
      box.innerHTML = "";
      if (!q) return;
      var inp = f.querySelector(".answer");
      if (q.skipped) { f.classList.add("is-skipped"); if (inp) inp.disabled = true; }
      else if (inp) inp.disabled = false;
      (q.warnings || []).forEach(function (w) {
        var s = document.createElement("span");
        s.className = "flag " + w.status;
        s.textContent = w.message;
        box.appendChild(s);
        f.classList.add(w.status === "error" ? "has-error" : "has-warning");
      });
    });

    // blok roster: flag di header kolom + tandai sel
    root.querySelectorAll(".roster th[data-var]").forEach(function (th) {
      var c = byVar[th.dataset.var];
      var box = th.querySelector(".col-flags");
      if (box) box.innerHTML = "";
      var bad = "";
      if (c && c.warnings && c.warnings.length) {
        c.warnings.forEach(function (w) {
          var s = document.createElement("span");
          s.className = "flag " + w.status;
          s.textContent = w.message;
          if (box) box.appendChild(s);
          if (w.status === "error") bad = "has-error";
          else if (!bad) bad = "has-warning";
        });
      }
      root.querySelectorAll('.rcell[data-var="' + th.dataset.var + '"]').forEach(function (td) {
        td.classList.remove("has-error", "has-warning");
        if (bad) td.classList.add(bad);
      });
    });

    // blok roster ART / list_art: sesuaikan jumlah baris bila jumlah ART berubah (tanpa muat ulang)
    function makeCell(col, cell, artIdx) {
      var td = document.createElement("td");
      td.className = "rcell";
      td.dataset.qid = cell.qid;
      td.dataset.art = artIdx;
      td.dataset.var = cell.var;
      var inp;
      if (col.options && col.options.length) {
        inp = document.createElement("select");
        inp.innerHTML = '<option value="">—</option>';
        col.options.forEach(function (o) {
          var op = document.createElement("option");
          op.value = o.code;
          op.textContent = o.code + " · " + o.label;
          inp.appendChild(op);
        });
      } else {
        inp = document.createElement("input");
      }
      inp.className = "answer";
      inp.value = cell.value || "";
      if (cell.skipped) { td.classList.add("is-skipped"); inp.disabled = true; inp.placeholder = "tidak berlaku"; }
      td.appendChild(inp);
      bind(inp, cell.qid, artIdx);
      return td;
    }

    state.blocks.forEach(function (b) {
      if (!b.rows || (b.repeat !== "art" && b.repeat !== "list_art")) return;
      var tbody = root.querySelector('.block[data-block="' + b.code + '"] .roster tbody');
      if (!tbody) return;

      // bila jumlah baris data (bukan header/warning) beda dengan state -> bangun ulang isi tabel
      var domData = tbody.querySelectorAll("tr:not(.group-header):not(.row-warn)").length;
      var stateData = (b.rows || []).filter(function (r) { return !r.group_header; }).length;
      if (domData === stateData) return;

      tbody.innerHTML = "";
      b.rows.forEach(function (row) {
        var tr = document.createElement("tr");
        if (row.group_header) {
          tr.className = "group-header";
          var gh = document.createElement("th");
          gh.colSpan = (b.columns || []).length + 1;
          gh.textContent = row.group_header;
          tr.appendChild(gh);
        } else {
          var rh = document.createElement("th");
          if (row.label) rh.className = "row-label";
          rh.textContent = row.label != null ? row.label : row.idx;
          tr.appendChild(rh);
          (b.columns || []).forEach(function (col, ci) {
            tr.appendChild(makeCell(col, (row.cells || [])[ci] || {}, row.idx));
          });
        }
        tbody.appendChild(tr);
      });
    });

    // blok roster/list: warning per SEL + baris warning (dari aturan per-baris)
    root.querySelectorAll("tr.row-warn").forEach(function (tr) { tr.remove(); });
    state.blocks.forEach(function (b) {
      if (!b.rows) return;
      var tbody = root.querySelector('.block[data-block="' + b.code + '"] .roster tbody');
      b.rows.forEach(function (row) {
        (row.cells || []).forEach(function (cell) {
          root.querySelectorAll('.rcell[data-var="' + cell.var + '"][data-art="' + row.idx + '"]').forEach(function (td) {
            // syarat lewati per baris: kunci/buka sel sesuai state terbaru
            var ri = td.querySelector(".answer");
            td.classList.toggle("is-skipped", !!cell.skipped);
            if (ri && root.dataset.canEdit === "1") {
              ri.disabled = !!cell.skipped;
              ri.placeholder = cell.skipped ? "tidak berlaku" : "";
            }
            if (cell.warnings && cell.warnings.length) {
              td.classList.add(cell.warnings[0].status === "error" ? "has-error" : "has-warning");
            }
          });
        });
        if (!tbody || !row.warnings || !row.warnings.length) return;
        var anchor = tbody.querySelector('.rcell[data-art="' + row.idx + '"]');
        if (!anchor) return;
        var tr = document.createElement("tr");
        tr.className = "row-warn";
        var td = document.createElement("td");
        td.colSpan = (b.columns || []).length + 1;
        row.warnings.forEach(function (w) {
          var s = document.createElement("span");
          s.className = "flag " + w.status;
          s.textContent = (row.label || ("Baris " + row.idx)) + ": " + w.message;
          td.appendChild(s);
        });
        tr.appendChild(td);
        anchor.parentNode.insertAdjacentElement("afterend", tr);
      });
    });

    docFlags.innerHTML = "";
    if (!state.doc_level.length) {
      docFlags.innerHTML = '<li class="muted">Tidak ada.</li>';
    } else {
      state.doc_level.forEach(function (w) {
        var li = document.createElement("li");
        li.className = "flag " + w.status;
        li.textContent = w.message;
        docFlags.appendChild(li);
      });
    }

    var dmap = {};
    state.derived.forEach(function (d) { dmap[d.var] = d.value; });
    (state.rekap || []).forEach(function (r) { dmap[r.var] = r.value; });
    [derivedTbl, rekapTbl].forEach(function (tbl) {
      if (!tbl) return;
      tbl.querySelectorAll("[data-dvar]").forEach(function (td) {
        var v = dmap[td.dataset.dvar];
        td.textContent = (v === null || v === undefined) ? "—" :
          (typeof v === "number" ? v.toLocaleString("id-ID") : v);
      });
    });
  }

  var timers = {};
  function schedule(key, qid, artIndex, input) {
    clearTimeout(timers[key]);
    timers[key] = setTimeout(function () {
      var val = input.value.trim();
      var holder = input.closest(".field, .rcell");
      var label = holder ? (holder.dataset.var || "") : "";
      var queueIt = function () {
        window.SusenasOutbox.put(docId, qid, artIndex, val, label);
        input.classList.add("pending-send");
        flash("tersimpan di HP — dikirim saat ada sinyal", true);
      };
      if (!navigator.onLine) { queueIt(); return; }
      flash("menyimpan…", true);
      post(qid, val, artIndex)
        .then(function (res) {
          input.classList.remove("pending-send");
          flash(res.saved ? "tersimpan" : "", true);
          repaint(res.state);
        })
        .catch(function (e) {
          // jaringan putus (TypeError) atau sesi habis (419/401) → antrekan di HP, jangan hilang
          if (e instanceof TypeError || /\((419|401)\)/.test(e.message)) queueIt();
          else flash(e.message, false);
        });
    }, 450);
  }

  function bind(input, qid, artIndex) {
    var key = qid + ":" + artIndex;
    var ev = input.tagName === "SELECT" ? "change" : "input";
    input.addEventListener(ev, function () { schedule(key, qid, artIndex, input); });
    if (input.tagName !== "SELECT") {
      input.addEventListener("blur", function () { schedule(key, qid, artIndex, input); });
    }
  }

  // isian yang masih antre di HP (mis. halaman dibuka dari salinan tanpa sinyal) ditampilkan di atas nilai lama
  window.SusenasOutbox.forDoc(docId).forEach(function (it) {
    var sel = it.art === "0" ? '.field[data-qid="' + it.qid + '"] .answer'
      : '.rcell[data-qid="' + it.qid + '"][data-art="' + it.art + '"] .answer';
    var inp = root.querySelector(sel);
    if (inp) { inp.value = it.value; inp.classList.add("pending-send"); }
  });
  document.addEventListener("susenas:sent", function (e) {
    if (e.detail.doc !== String(docId)) return;
    if (!window.SusenasOutbox.forDoc(docId).length) {
      root.querySelectorAll(".pending-send").forEach(function (i) { i.classList.remove("pending-send"); });
    }
    if (e.detail.state) repaint(e.detail.state);
  });

  root.querySelectorAll(".field").forEach(function (field) {
    var input = field.querySelector(".answer");
    if (input) bind(input, field.dataset.qid, 0);
  });
  root.querySelectorAll(".rcell").forEach(function (cell) {
    var input = cell.querySelector(".answer");
    if (input) bind(input, cell.dataset.qid, cell.dataset.art);
  });

  // --- lompat ke isu berikutnya (Increment 81) ---
  // seed .has-error / .has-warning dari flag yang sudah dirender server
  root.querySelectorAll(".field").forEach(function (f) {
    var flags = f.querySelectorAll(".q-flags .flag");
    if (!flags.length) return;
    var err = Array.prototype.some.call(flags, function (s) { return s.classList.contains("error"); });
    f.classList.add(err ? "has-error" : "has-warning");
  });

  // Tombol "Terapkan jumlah ART": setel R301 lalu sesuaikan baris roster & list_art
  var applyArt = document.getElementById("apply-art");
  if (applyArt) {
    var artField = applyArt.closest(".field");
    applyArt.addEventListener("click", function () {
      var fld = artField && artField.querySelector(".answer");
      var n = parseInt((fld && fld.value) || "0", 10);
      if (isNaN(n) || n < 0) { flash("isi banyaknya anggota rumah tangga dulu", false); return; }
      var domMax = 0;
      root.querySelectorAll('.block[data-block] .roster tbody tr:not(.group-header) > th').forEach(function (th) {
        var t = th.textContent.trim();
        var m = /(\d+)\s*$/.exec(t);
        if (m) domMax = Math.max(domMax, parseInt(m[1], 10));
      });
      if (n < domMax && !confirm("Kurangi jumlah anggota rumah tangga ke " + n + "? Isian untuk anggota " + (n + 1) + "–" + domMax + " akan dihapus dan tidak bisa dikembalikan.")) return;
      applyArt.disabled = true;
      flash("menyesuaikan…", true);
      fetch("/entri/" + docId + "/jumlah-art", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-TOKEN": CSRF, "X-Requested-With": "XMLHttpRequest" },
        body: "count=" + n,
      }).then(function (r) { if (!r.ok) throw new Error("gagal (" + r.status + ")"); return r.json(); })
        .then(function (d) {
          repaint(d.state);
          flash(d.dropped ? d.dropped + " isian anggota rumah tangga dihapus" : "baris disesuaikan", true);
        })
        .catch(function (e) { flash(e.message, false); })
        .finally(function () { applyArt.disabled = false; });
    });
  }

  var jump = document.getElementById("next-issue");
  if (jump) {
    var cursor = -1;
    jump.addEventListener("click", function () {
      var items = Array.prototype.slice.call(
        root.querySelectorAll(".field.has-error, .field.has-warning, .rcell.has-error, .rcell.has-warning"));
      if (!items.length) { flash("tidak ada isu tersisa", true); return; }
      cursor = (cursor + 1) % items.length;
      var el = items[cursor];
      var block = el.closest(".block");
      if (block) { var body = block.querySelector(".block-body"); if (body) body.style.display = ""; }
      el.scrollIntoView({ behavior: "smooth", block: "center" });
      el.classList.add("issue-flash");
      setTimeout(function () { el.classList.remove("issue-flash"); }, 1200);
      var inp = el.querySelector(".answer");
      if (inp && !inp.disabled) setTimeout(function () { inp.focus(); }, 300);
      jump.textContent = "↧ isu berikutnya (" + (cursor + 1) + "/" + items.length + ")";
    });
  }
})();

// Detail teknis (nama variabel, rumus) di halaman admin: tersembunyi kecuali dicentang; diingat per peramban.
(function () {
  var boxes = document.querySelectorAll("[data-tech-toggle]");
  if (!boxes.length) return;
  var on = false;
  try { on = localStorage.getItem("susenas.tech") === "1"; } catch (e) {}
  function apply() {
    document.body.classList.toggle("show-tech", on);
    boxes.forEach(function (b) { b.checked = on; });
  }
  boxes.forEach(function (b) {
    b.addEventListener("change", function () {
      on = b.checked;
      try { localStorage.setItem("susenas.tech", on ? "1" : "0"); } catch (e) {}
      apply();
    });
  });
  apply();
})();

// Tombol kembali "pintar" (komponen <x-back smart>): kembali ke halaman sebelumnya di aplikasi ini
// bila ada & berbeda dari halaman sekarang; selain itu ikuti href (halaman induk).
(function () {
  document.querySelectorAll("[data-back-smart]").forEach(function (a) {
    a.addEventListener("click", function (e) {
      try {
        var ref = document.referrer ? new URL(document.referrer) : null;
        if (ref && ref.origin === location.origin && ref.pathname !== location.pathname
            && ref.pathname !== "/login" && history.length > 1) {
          e.preventDefault();
          history.back();
        }
      } catch (err) {}
    });
  });
})();

// Tata letak desktop: <body> dikunci (overflow hidden, tinggi 100dvh) & yang digulir hanya .content.
// Lompatan ke jangkar (#blok-IV.1) atau scrollIntoView() ikut menggeser DOKUMEN yang terkunci itu
// beberapa piksel — lalu tak bisa digulir balik, bagian atas (tombol kembali) tertutup bilah atas.
// Jaga: selama body terkunci, posisi gulir dokumen selalu dikembalikan ke 0.
(function () {
  function locked() { return getComputedStyle(document.body).overflowY === "hidden"; }
  function reset() {
    if (locked() && (window.scrollY || document.documentElement.scrollTop)) window.scrollTo(0, 0);
  }
  window.addEventListener("scroll", reset, { passive: true });
  window.addEventListener("load", reset);
  window.addEventListener("hashchange", function () { setTimeout(reset, 0); });
})();
