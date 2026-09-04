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

  main.querySelectorAll(".block").forEach(function (sec) {
    var code = sec.dataset.block;
    if (collapsed[code]) sec.classList.add("collapsed");
    var head = sec.querySelector("[data-block-toggle]");
    if (head) head.addEventListener("click", function (e) {
      if (e.target.tagName === "A") return;
      sec.classList.toggle("collapsed");
      collapsed[code] = sec.classList.contains("collapsed");
      persist();
    });
  });

  // klik navigasi: pastikan blok terbuka lalu scroll
  document.querySelectorAll("[data-nav-block]").forEach(function (a) {
    a.addEventListener("click", function () {
      var sec = main.querySelector('.block[data-block="' + a.dataset.navBlock + '"]');
      if (sec) { sec.classList.remove("collapsed"); collapsed[a.dataset.navBlock] = false; persist(); }
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
      flash("menyimpan…", true);
      post(qid, input.value.trim(), artIndex)
        .then(function (res) {
          flash(res.saved ? "tersimpan" : "", true);
          repaint(res.state);
        })
        .catch(function (e) { flash(e.message, false); });
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
      if (isNaN(n) || n < 0) { flash("isi Banyaknya ART dulu", false); return; }
      var domMax = 0;
      root.querySelectorAll('.block[data-block] .roster tbody tr:not(.group-header) > th').forEach(function (th) {
        var t = th.textContent.trim();
        var m = /(\d+)\s*$/.exec(t);
        if (m) domMax = Math.max(domMax, parseInt(m[1], 10));
      });
      if (n < domMax && !confirm("Kurangi jumlah ART ke " + n + "? Isian untuk ART " + (n + 1) + "–" + domMax + " akan dihapus dan tidak bisa dikembalikan.")) return;
      applyArt.disabled = true;
      flash("menyesuaikan…", true);
      fetch("/entri/" + docId + "/jumlah-art", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-TOKEN": CSRF, "X-Requested-With": "XMLHttpRequest" },
        body: "count=" + n,
      }).then(function (r) { if (!r.ok) throw new Error("gagal (" + r.status + ")"); return r.json(); })
        .then(function (d) {
          repaint(d.state);
          flash(d.dropped ? d.dropped + " isian ART dihapus" : "baris disesuaikan", true);
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
