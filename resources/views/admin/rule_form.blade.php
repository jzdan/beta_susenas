@extends('layouts.app')
@php($nav = 'aturan')
@section('title', ($edit ? "Ubah aturan {$edit->code}" : 'Buat aturan baru').' · Sistem SUSENAS')
@section('content')
{{-- Inc 153: menyunting / membuat aturan di halaman sendiri, dengan pemeriksaan langsung
     (rumus terbaca?, menyala di berapa sampel?, contoh pesan). Daftar harian tetap di /admin/aturan. --}}
<p><a class="btn-ghost" href="{{ route('admin.aturan', ['qv' => $qv->id]) }}">← Aturan Validasi</a></p>
<h1>{{ $edit ? 'Ubah aturan' : 'Buat aturan baru' }}
  @if($edit)<small class="mono">{{ $edit->code }}</small>@endif</h1>
<p class="muted">Versi kuesioner {{ $qv->code }}@if($qv->cycle), dipakai sampel {{ $qv->cycle->name }}@endif.</p>

@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

@if(!$edit)
<nav class="mode-switch">
  <a class="btn-ghost {{ $mode === 'terpandu' ? 'on' : '' }}" href="{{ route('admin.aturan.baru', ['qv' => $qv->id]) }}">Pilih dari daftar (terpandu)</a>
  <a class="btn-ghost {{ $mode === 'rumus' ? 'on' : '' }}" href="{{ route('admin.aturan.baru', ['qv' => $qv->id, 'mode' => 'rumus']) }}">Tulis rumus sendiri</a>
</nav>
@endif

@if($mode === 'terpandu')
{{-- ===================== formulir terpandu ===================== --}}
<section class="panel">
  <p class="muted small">Pilih jenis pemeriksaan dan isi angkanya. Sistem yang menyusun rumusnya.</p>
  <form method="post" action="{{ route('admin.aturan.terpandu') }}" class="stack-form" id="wizform">
    @csrf
    <input type="hidden" name="qv" value="{{ $qv->id }}">
    <div class="rule-grid">
      <label>Kode aturan <input name="code" value="{{ old('code') }}" placeholder="mis. IV1-BERAS-WAJIB" required></label>
      <label>Tingkat
        <select name="severity">
          <option value="warning" @selected(old('severity')==='warning')>Peringatan (perlu ditinjau)</option>
          <option value="error" @selected(old('severity')==='error')>Kesalahan (menahan tanda bersih)</option>
        </select>
      </label>
      <label>Berlaku pada
        <select name="block">
          <option value="">Seluruh sampel</option>
          @foreach ($wizardBlocks as $bl)
            <option value="{{ $bl->code }}" @selected(old('block')===$bl->code)>Blok {{ $bl->code }}: {{ $bl->title }}</option>
          @endforeach
        </select>
      </label>
    </div>

    <label>Jenis pemeriksaan
      <select name="kind" id="wiz-kind">
        <option value="wajib" @selected(old('kind')==='wajib')>Rincian wajib diisi</option>
        <option value="wajib_jika" @selected(old('kind')==='wajib_jika')>Rincian wajib diisi JIKA rincian lain memenuhi syarat</option>
        <option value="rentang" @selected(old('kind')==='rentang')>Nilai harus di dalam rentang min–maks</option>
        <option value="batas" @selected(old('kind')==='batas')>Nilai tidak boleh lebih / kurang dari angka</option>
        <option value="banding" @selected(old('kind')==='banding')>Dua rincian harus sama / harus berbeda</option>
        <option value="kombinasi" @selected(old('kind')==='kombinasi')>Gabungan beberapa syarat</option>
      </select>
    </label>

    <div class="wiz-part" data-kind="wajib">
      @include('admin.partials._rulefield', ['name' => 'field_a', 'label' => 'Rincian yang wajib diisi'])
    </div>

    <div class="wiz-part" data-kind="wajib_jika">
      <div class="row-form">
        @include('admin.partials._rulefield', ['name' => 'field_a', 'label' => 'Rincian syarat (A)'])
        <label>Syarat
          <select name="cond_op">
            <option value="terisi">A terisi</option>
            <option value="kosong">A kosong</option>
            <option value="==">A sama dengan…</option>
            <option value="!=">A tidak sama dengan…</option>
            <option value="&gt;">A lebih dari…</option>
            <option value="&lt;">A kurang dari…</option>
          </select>
        </label>
        <label>Nilai <input name="cond_val" value="{{ old('cond_val') }}" placeholder="mis. 1" class="mono" style="width:100px"></label>
      </div>
      @include('admin.partials._rulefield', ['name' => 'field_b', 'label' => 'Rincian yang jadi wajib diisi (B)'])
    </div>

    <div class="wiz-part" data-kind="rentang">
      @include('admin.partials._rulefield', ['name' => 'field_a', 'label' => 'Rincian yang diperiksa'])
      <div class="row-form">
        <label>Minimal <input name="min" value="{{ old('min') }}" class="mono" inputmode="decimal" style="width:120px"></label>
        <label>Maksimal <input name="max" value="{{ old('max') }}" class="mono" inputmode="decimal" style="width:120px"></label>
      </div>
    </div>

    <div class="wiz-part" data-kind="batas">
      @include('admin.partials._rulefield', ['name' => 'field_a', 'label' => 'Rincian yang diperiksa'])
      <div class="row-form">
        <label>Tidak boleh
          <select name="limit_op">
            <option value="&gt;">lebih dari</option>
            <option value="&lt;">kurang dari</option>
            <option value="&gt;=">lebih dari atau sama dengan</option>
            <option value="&lt;=">kurang dari atau sama dengan</option>
          </select>
        </label>
        <label>Angka <input name="limit_val" value="{{ old('limit_val') }}" class="mono" inputmode="decimal" style="width:120px"></label>
      </div>
    </div>

    <div class="wiz-part" data-kind="banding">
      @include('admin.partials._rulefield', ['name' => 'field_a', 'label' => 'Rincian pertama (A)'])
      <label>Hubungan
        <select name="banding_op">
          <option value="sama">A harus SAMA dengan B</option>
          <option value="beda">A harus BERBEDA dari B</option>
        </select>
      </label>
      @include('admin.partials._rulefield', ['name' => 'field_b', 'label' => 'Rincian kedua (B)'])
    </div>

    <div class="wiz-part" data-kind="kombinasi">
      <p class="muted small" style="margin:0 0 8px">Gabungkan minimal 2 syarat, mis. <em>aset banyak TAPI belanja makanan sangat rendah</em>.</p>
      <label>Syarat digabung dengan
        <select name="join">
          <option value="and">DAN: semua syarat harus benar</option>
          <option value="or">ATAU: cukup salah satu benar</option>
        </select>
      </label>
      <div id="kombi-rows">
        @for ($i = 0; $i < 5; $i++)
        <div class="row-form kombi-row" @if($i >= 2) hidden @endif style="margin-top:6px">
          @include('admin.partials._rulefield', ['name' => 'cond_field[]', 'label' => 'Syarat '.($i + 1)])
          <label>Syarat
            <select name="cond_op2[]">
              <option value="terisi">terisi</option>
              <option value="kosong">kosong</option>
              <option value="==">sama dengan…</option>
              <option value="!=">tidak sama dengan…</option>
              <option value="&gt;">lebih dari…</option>
              <option value="&lt;">kurang dari…</option>
              <option value="&gt;=">≥…</option>
              <option value="&lt;=">≤…</option>
            </select>
          </label>
          <label>Nilai <input name="cond_val2[]" class="mono" inputmode="decimal" style="width:100px"></label>
        </div>
        @endfor
      </div>
      <button type="button" class="btn-ghost" id="kombi-add" style="margin-top:6px">+ Tambah syarat</button>
    </div>

    <label>Pesan yang tampil ke pemeriksa
      <input name="message" value="{{ old('message') }}" placeholder="mis. Nilai beras terisi tetapi kuantitas kosong." required>
    </label>
    <label>Keterangan (opsional)
      <input name="note" value="{{ old('note') }}" placeholder="apa yang diperiksa aturan ini">
    </label>
    <div class="save-bar">
      <button type="submit">Buat aturan</button>
      <button type="button" class="btn-ghost" id="btn-wiz-test">Uji: berapa sampel yang akan kena?</button>
      <span id="wiz-test-out" class="live" hidden></span>
    </div>
  </form>
</section>

@else
{{-- ===================== tulis rumus sendiri ===================== --}}
<form method="post" action="{{ route('admin.aturan.save') }}" class="panel stack-form" id="ruleform">
  @csrf
  <input type="hidden" name="qv" value="{{ $qv->id }}">
  @if($edit)<input type="hidden" name="rule_id" value="{{ $edit->id }}">@endif
  @php($rb = old('row_block', $edit->row_block ?? ''))

  <div class="rule-step">
    <h2><span class="step-no">1</span>Apa yang diperiksa</h2>
    <div class="rule-grid">
      <label>Kode <input name="code" value="{{ old('code', $edit->code ?? '') }}" required class="mono"></label>
      <label>Tingkat
        <select name="severity">
          @php($sv = old('severity', $edit->severity ?? 'warning'))
          <option value="warning" @selected($sv === 'warning')>Peringatan (perlu ditinjau)</option>
          <option value="error" @selected($sv === 'error')>Kesalahan (menahan tanda bersih)</option>
        </select>
      </label>
      <label>Diperiksa
        <select name="row_block" id="row_block">
          <option value="">Sekali per sampel</option>
          @foreach ($rowBlocks as $bl)
            <option value="{{ $bl->code }}" @selected($rb === $bl->code)>Tiap baris Blok {{ $bl->code }}: {{ \Illuminate\Support\Str::limit($bl->title, 40) }}</option>
          @endforeach
          @if($rb !== '' && !$rowBlocks->contains('code', $rb))
            <option value="{{ $rb }}" selected>Tiap baris Blok {{ $rb }} (blok tidak ditemukan)</option>
          @endif
        </select>
      </label>
    </div>
    <details class="mt">
      <summary style="cursor:pointer" class="small">Pengaturan lain (jenis aturan, variabel sasaran, keterangan)</summary>
      <div class="rule-grid mt">
        <label>Jenis aturan
          <select name="scope">
            @php($sc = old('scope', $edit->scope ?? 'dokumen'))
            @foreach (Istilah::SCOPE as $k => $lbl)<option value="{{ $k }}" @selected($sc === $k)>{{ $lbl }}</option>@endforeach
            @if(!array_key_exists($sc, Istilah::SCOPE))<option value="{{ $sc }}" selected>{{ Istilah::scope($sc) }}</option>@endif
          </select>
        </label>
        <label>Variabel sasaran <input name="target_var" value="{{ old('target_var', $edit->target_var ?? '') }}" class="mono" placeholder="opsional"></label>
      </div>
      <label class="mt">Keterangan <input name="note" value="{{ old('note', $edit->note ?? '') }}" placeholder="apa yang diperiksa aturan ini"></label>
    </details>
  </div>

  <div class="rule-step">
    <h2><span class="step-no">2</span>Rumus</h2>
    <p class="muted small">Rumus yang bernilai <strong>benar</strong> berarti ada masalah.
      <a href="#bantuan" onclick="document.getElementById('bantuan').open = true">Bantuan rumus &amp; daftar variabel</a></p>
    <textarea name="expr" id="expr" rows="3" required spellcheck="false">{{ old('expr', $edit->expr ?? '') }}</textarea>
    <div id="expr-live" class="live" hidden></div>
  </div>

  <div class="rule-step">
    <h2><span class="step-no">3</span>Pesan ke pemeriksa</h2>
    <input name="message" id="message" value="{{ old('message', $edit->message ?? '') }}" required>
    <p class="muted small">Tulisan dalam kurung kurawal diisi otomatis: <span class="mono">{nama_variabel}</span> menjadi nilainya,
      <span class="mono">{=rumus}</span> menjadi hasil hitung.</p>
    <div id="msg-preview" class="msg-preview" hidden></div>
  </div>

  <div class="save-bar">
    <button type="submit">Simpan</button>
    <a class="btn-ghost" href="{{ route('admin.aturan', ['qv' => $qv->id]) }}">Batal</a>
    <span id="impact-live" class="muted small"></span>
  </div>
</form>
@endif

<details class="panel" id="bantuan">
  <summary style="cursor:pointer;font-weight:600">Bantuan rumus &amp; daftar variabel</summary>
  <div class="mt small">
    <p><strong>Fungsi:</strong> <span class="mono">blank(x)</span> x kosong · <span class="mono">filled(x)</span> x terisi ·
      <span class="mono">n(x)</span> angka dari x · <span class="mono">abs round floor min max sum coalesce</span> ·
      <span class="mono">any_filled all_filled count_filled</span> · <span class="mono">ymd("2026-03-10")</span> untuk tanggal.</p>
    <p><strong>Penghubung:</strong> <span class="mono">and or not</span>, perbandingan <span class="mono">== != &gt; &lt; &gt;= &lt;=</span>.</p>
    @if($hasRoster)
    <p><strong>Anggota rumah tangga:</strong> <span class="mono">roster_count(var, kode)</span> berapa anggota bernilai kode ·
      <span class="mono">roster_any / roster_all / roster_max / roster_sum / roster_first / roster(var, nomor)</span>.</p>
    @endif
  </div>
  <input type="search" id="kamus-cari" placeholder="cari nama variabel / keterangan" style="min-width:260px;margin:6px 0">
  <div class="tablewrap tall">
  <table class="tbl" id="kamus-tabel">
    <thead><tr><th style="width:220px">Nama</th><th style="width:170px">Dari</th><th>Keterangan</th></tr></thead>
    <tbody>
      <tr><td class="mono">sampel_wilayah</td><td>Daftar sampel</td><td>Kode desa sampel</td></tr>
      <tr><td class="mono">sampel_bs</td><td>Daftar sampel</td><td>Nomor blok sensus</td></tr>
      <tr><td class="mono">sampel_ruta</td><td>Daftar sampel</td><td>Nomor urut rumah tangga</td></tr>
      <tr><td class="mono">sampel_krt</td><td>Daftar sampel</td><td>Nama kepala rumah tangga</td></tr>
      @foreach ($wizardBlocks as $bl)
        @foreach ($bl->questions as $q)
          <tr><td class="mono">{{ $q->var_name }}</td><td>Blok {{ $bl->code }} · {{ $q->code_rincian }}</td><td>{{ $q->label }}</td></tr>
        @endforeach
      @endforeach
      @foreach ($derivedFields as $df)
        <tr><td class="mono">{{ $df->var_name }}</td><td>Nilai turunan</td><td>{{ $df->label }} (dihitung sistem)</td></tr>
      @endforeach
      @foreach ($ruleParams as $p)
        <tr><td class="mono">{{ $p->name }}</td><td>Parameter</td><td>Nilai tetap {{ $p->value }}{{ $p->label ? ': '.$p->label : '' }}</td></tr>
      @endforeach
      @foreach ($refKeys as $k)
        <tr><td class="mono">ref_{{ $k }}</td><td>Patokan baris</td><td>Nilai <span class="mono">{{ $k }}</span> komoditas, hanya untuk aturan yang diperiksa tiap baris</td></tr>
      @endforeach
      @if($hasRoster)
        <tr><td class="mono">&lt;var&gt;__n</td><td>Anggota rumah tangga</td><td>Jumlah anggota yang mengisi &lt;var&gt;. Juga <span class="mono">__sum __count1 __count2 __max __min</span></td></tr>
        <tr><td class="mono">art_terisi</td><td>Anggota rumah tangga</td><td>Jumlah baris anggota rumah tangga yang terisi</td></tr>
      @endif
    </tbody>
  </table>
  </div>
</details>

@if($edit)
<section class="panel danger-zone">
  <h2>Hapus aturan</h2>
  <p class="muted small">Aturan dan semua hasil pemeriksaannya dihapus permanen. Kalau hanya ingin berhenti memakainya,
    cukup <em>matikan</em> dari daftar aturan.</p>
  <form method="post" action="{{ route('admin.aturan.del', $edit) }}"
    onsubmit="return confirm('Hapus aturan {{ $edit->code }} secara permanen?')">
    @csrf<button type="submit" class="btn-ghost" style="color:var(--err)">Hapus aturan {{ $edit->code }}</button>
  </form>
</section>
@endif

@push('scripts')
<style>
  .wiz-part { display: none; margin: 4px 0 6px; padding: 10px 12px; border-left: 3px solid var(--line-strong); }
  .wiz-part.on { display: block; }
  .kombi-row[hidden] { display: none; }
  #kombi-add[hidden] { display: none; }
</style>
<script>
(function () {
  var csrf = document.querySelector('meta[name=csrf-token]').content;
  var nf = function (n) { return Number(n).toLocaleString("id-ID"); };
  var esc = function (t) { var d = document.createElement("div"); d.textContent = t; return d.innerHTML; };

  // kamus variabel: cari
  var kc = document.getElementById("kamus-cari");
  if (kc) kc.addEventListener("input", function () {
    var q = kc.value.toLowerCase().trim();
    document.querySelectorAll("#kamus-tabel tbody tr").forEach(function (tr) {
      tr.hidden = q !== "" && tr.textContent.toLowerCase().indexOf(q) === -1;
    });
  });

  // ---- formulir terpandu
  var kind = document.getElementById("wiz-kind");
  if (kind) {
    var sync = function () {
      document.querySelectorAll(".wiz-part").forEach(function (el) {
        var on = el.dataset.kind === kind.value;
        el.classList.toggle("on", on);
        el.querySelectorAll("input,select").forEach(function (f) { f.disabled = !on; });
      });
    };
    kind.addEventListener("change", sync);
    sync();
  }
  var kombiAdd = document.getElementById("kombi-add");
  if (kombiAdd) kombiAdd.addEventListener("click", function () {
    var hiddenRow = document.querySelector("#kombi-rows .kombi-row[hidden]");
    if (hiddenRow) hiddenRow.hidden = false;
    if (!document.querySelector("#kombi-rows .kombi-row[hidden]")) kombiAdd.hidden = true;
  });
  var wt = document.getElementById("btn-wiz-test");
  if (wt) wt.addEventListener("click", function () {
    var out = document.getElementById("wiz-test-out");
    out.hidden = false; out.className = "live"; out.textContent = "menghitung…";
    fetch("{{ route('admin.aturan.terpandu.uji') }}", {
      method: "POST", headers: { "X-CSRF-TOKEN": csrf },
      body: new URLSearchParams(new FormData(document.getElementById("wizform")))
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { out.className = "live bad"; out.textContent = "✕ " + d.error; return; }
      out.className = "live ok";
      out.textContent = d.fire_docs
        ? "Akan kena di " + nf(d.fire_docs) + " dari " + nf(d.docs) + " sampel"
        : "Tidak kena di sampel mana pun (0 dari " + nf(d.docs) + ")";
    }).catch(function (e) { out.className = "live bad"; out.textContent = e.message; });
  });

  // ---- tulis rumus sendiri: periksa langsung saat mengetik
  var expr = document.getElementById("expr");
  if (!expr) return;
  var msg = document.getElementById("message"), rb = document.getElementById("row_block");
  var exprLive = document.getElementById("expr-live"), impact = document.getElementById("impact-live"),
      prev = document.getElementById("msg-preview");
  var timer = null, seq = 0;
  function check() {
    var my = ++seq;
    if (!expr.value.trim()) { exprLive.hidden = true; prev.hidden = true; impact.textContent = ""; return; }
    exprLive.hidden = false; exprLive.className = "live"; exprLive.textContent = "memeriksa…";
    fetch("{{ route('admin.aturan.pratinjau') }}", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-TOKEN": csrf },
      body: new URLSearchParams({ qv: "{{ $qv->id }}", expr: expr.value, row_block: rb.value, message: msg.value })
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (my !== seq) return;   // ada ketikan lebih baru
      if (!d.ok) {
        exprLive.className = "live bad"; exprLive.textContent = "✕ Rumus tidak bisa dibaca: " + d.error;
        impact.textContent = ""; prev.hidden = true; return;
      }
      exprLive.className = "live ok";
      exprLive.textContent = "✓ Rumus terbaca" + (d.eval_errors ? " (" + nf(d.eval_errors) + " sampel gagal dihitung, cek nama variabel)" : "");
      impact.innerHTML = d.fire_docs
        ? "Kena di <strong>" + nf(d.fire_docs) + "</strong> dari " + nf(d.docs) + " sampel"
          + (d.fire_rows !== d.fire_docs ? " (" + nf(d.fire_rows) + " baris)" : "")
        : "Tidak kena di sampel mana pun (0 dari " + nf(d.docs) + ")";
      if (d.example) {
        prev.hidden = false;
        prev.innerHTML = "<span class='muted small'>Contoh yang dibaca pemeriksa (isian #" + d.example.doc_id
          + (d.example.row !== null ? ", baris " + esc(String(d.example.row)) : "") + "):</span><br>" + esc(d.example.text);
      } else if (msg.value.trim()) {
        prev.hidden = false;
        prev.innerHTML = "<span class='muted small'>Belum ada sampel yang kena, jadi contoh pesan belum bisa ditampilkan.</span>";
      } else { prev.hidden = true; }
    }).catch(function (e) { if (my === seq) { exprLive.className = "live bad"; exprLive.textContent = e.message; } });
  }
  function later() { clearTimeout(timer); timer = setTimeout(check, 600); }
  expr.addEventListener("input", later);
  msg.addEventListener("input", later);
  rb.addEventListener("change", check);
  check();
})();
</script>
@endpush
@endsection
