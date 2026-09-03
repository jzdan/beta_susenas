@extends('layouts.app')
@php($nav = 'aturan')
@section('title', 'Aturan Validasi · Sistem SUSENAS')
@section('content')
<h1>Aturan Validasi</h1>
<p class="muted">Konvensi: ekspresi bernilai <strong>TRUE = ada masalah</strong>.
  Fungsi: <span class="mono">blank filled num n coalesce abs round floor min max sum any_filled all_filled count_filled ymd</span>.
  <br><span class="mono">ymd("2026-03-10")</span> → 20260310 untuk membandingkan tanggal.
  Metadata sampel tersedia: <span class="mono">sampel_wilayah · sampel_bs · sampel_ruta · sampel_krt</span>.
  <br>Roster/antar-ART: <span class="mono">roster(var, idx) · roster_count(var, kode) · roster_all(var, kode) · roster_any(var, kode) · roster_first(nilaiVar, kunciVar, kode) · roster_max(var) · roster_sum(var)</span>
  — argumen <em>var</em> ditulis sebagai nama polos (mis. <span class="mono">roster_count(r403, 1)</span>).</p>

@if ($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <h2>{{ $edit ? 'Sunting aturan '.$edit->code : 'Tambah aturan' }}</h2>
  <form method="post" action="{{ route('admin.aturan.save') }}" class="stack-form">
    @csrf
    @if($edit)<input type="hidden" name="rule_id" value="{{ $edit->id }}">@endif
    <div class="row-form">
      <label>Kode <input name="code" value="{{ $edit->code ?? '' }}" required></label>
      <label>Severity
        <select name="severity">
          <option value="warning" @selected($edit && $edit->severity === 'warning')>warning</option>
          <option value="error" @selected($edit && $edit->severity === 'error')>error</option>
        </select>
      </label>
      <label>Scope <input name="scope" value="{{ $edit->scope ?? 'dokumen' }}"></label>
      <label>Target var <input name="target_var" value="{{ $edit->target_var ?? '' }}" class="mono" placeholder="opsional"></label>
      <label>Row block <input name="row_block" value="{{ $edit->row_block ?? '' }}" class="mono" placeholder="kosong = tingkat dokumen"></label>
    </div>
    <p class="muted small">Isi <em>Row block</em> dengan kode blok berulang agar aturan dievaluasi <strong>per baris</strong>
      (konteks lokal: nilai kolom baris + <span class="mono">ref_&lt;kunci&gt;</span>).</p>
    <label>Ekspresi (TRUE = masalah)
      <input name="expr" id="expr" value="{{ $edit->expr ?? '' }}" class="mono" required>
    </label>
    <label>Pesan <input name="message" value="{{ $edit->message ?? '' }}" required></label>
    <div class="row-form">
      <button type="submit">Simpan</button>
      @if($edit)<a class="btn-ghost" href="{{ route('admin.aturan') }}">Batal</a>@endif
    </div>
  </form>

  <div class="tester">
    <h3>Uji ekspresi</h3>
    <div class="row-form">
      <select id="test-doc">
        @foreach ($docs as $d)<option value="{{ $d->id }}">Dokumen #{{ $d->id }} (sampel {{ $d->sample_id }})</option>@endforeach
      </select>
      <button type="button" class="btn-ghost" id="btn-test">Jalankan</button>
      <button type="button" class="btn-ghost" id="btn-impact">Pratinjau dampak (semua dokumen)</button>
    </div>
    <div id="test-out" class="test-out muted">Belum diuji.</div>
    <p class="muted small">“Pratinjau dampak” menjalankan ekspresi ini terhadap seluruh dokumen versi aktif
      tanpa menyimpan apa pun — untuk mengukur berapa yang akan menyala sebelum aturan diaktifkan.</p>
  </div>
</section>

<section class="panel">
  <h2>Daftar aturan ({{ $rules->count() }} dari {{ $total }})</h2>

  <form method="get" class="filters">
    <select name="sev">
      <option value="">semua severity</option>
      <option value="error" @selected($filters['sev'] === 'error')>error</option>
      <option value="warning" @selected($filters['sev'] === 'warning')>warning</option>
    </select>
    <select name="scope">
      <option value="">semua scope</option>
      @foreach ($scopes as $s)<option value="{{ $s }}" @selected($filters['scope'] === $s)>{{ $s }}</option>@endforeach
    </select>
    <select name="active">
      <option value="">aktif &amp; nonaktif</option>
      <option value="ya" @selected($filters['active'] === 'ya')>aktif saja</option>
      <option value="tidak" @selected($filters['active'] === 'tidak')>nonaktif saja</option>
    </select>
    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="cari kode / pesan / ekspresi" style="min-width:200px">
    <button type="submit">Filter</button>
    @if(array_filter($filters))<a class="btn-ghost" href="{{ route('admin.aturan') }}">Reset</a>@endif
  </form>

  <form method="post" action="{{ route('admin.aturan.toggle-massal') }}">
  @csrf
  <div class="row-form" style="margin:6px 0">
    <label class="small"><input type="checkbox" id="rule-all"> pilih semua terlihat</label>
    <button type="submit" name="aksi" value="aktif" class="btn-ghost">aktifkan terpilih</button>
    <button type="submit" name="aksi" value="nonaktif" class="btn-ghost">nonaktifkan terpilih</button>
  </div>
  <div class="tablewrap tall">
  <table class="tbl">
    <thead><tr><th></th><th>Kode</th><th>Sev</th><th>Scope</th><th>Target</th><th>Ekspresi</th><th>Pesan</th><th>Aktif</th><th></th></tr></thead>
    <tbody>
    @foreach ($rules as $r)
      <tr class="{{ $r->active ? '' : 'row-off' }}">
        <td><input type="checkbox" class="rule-pick" name="ids[]" value="{{ $r->id }}"></td>
        <td class="mono">{{ $r->code }}</td>
        <td><span class="flag {{ $r->severity }}">{{ $r->severity }}</span></td>
        <td class="small">{{ $r->scope }}@if($r->row_block) <span class="mono muted">/{{ $r->row_block }}</span>@endif</td>
        <td class="mono small">{{ $r->target_var ?: '—' }}</td>
        <td class="mono small">{{ $r->expr }}
          @if($ruleErrs[$r->id])<div class="flag error">sintaks: {{ $ruleErrs[$r->id] }}</div>@endif
        </td>
        <td class="small">{{ $r->message }}</td>
        <td>
          <button formaction="{{ route('admin.aturan.toggle', $r) }}" class="btn-ghost">{{ $r->active ? 'nonaktif' : 'aktif' }}</button>
        </td>
        <td class="right nowrap">
          <a class="btn-ghost" href="{{ route('admin.aturan.edit', $r) }}">Sunting</a>
          <button formaction="{{ route('admin.aturan.del', $r) }}" class="btn-ghost"
            onclick="return confirm('Hapus aturan {{ $r->code }}?')">Hapus</button>
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  </form>
</section>

@push('scripts')
<script>
(function () {
  var all = document.getElementById("rule-all");
  if (!all) return;
  all.addEventListener("change", function () {
    document.querySelectorAll(".rule-pick").forEach(function (c) { c.checked = all.checked; });
  });
})();
</script>
@endpush

@push('scripts')
<script>
document.getElementById("btn-test").addEventListener("click", function () {
  var out = document.getElementById("test-out");
  out.textContent = "menguji…";
  var body = new URLSearchParams({ expr: document.getElementById("expr").value, document_id: document.getElementById("test-doc").value });
  fetch("{{ route('admin.aturan.test') }}", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-TOKEN": document.querySelector('meta[name=csrf-token]').content },
    body: body
  }).then(function (r) { return r.json(); }).then(function (d) {
    if (!d.ok) { out.className = "test-out flag error"; out.textContent = "Error: " + d.error; return; }
    out.className = "test-out " + (d.fires ? "flag warning" : "flag");
    var lines = "hasil = " + d.value + "  →  " + (d.fires ? "ATURAN MENYALA (ada masalah)" : "aman") + "\n\nkonteks dokumen:\n";
    Object.keys(d.context).forEach(function (k) { lines += "  " + k + " = " + d.context[k] + "\n"; });
    out.textContent = lines;
  }).catch(function (e) { out.className = "test-out flag error"; out.textContent = e.message; });
});

document.getElementById("btn-impact").addEventListener("click", function () {
  var out = document.getElementById("test-out");
  out.className = "test-out muted";
  out.textContent = "menghitung dampak…";
  var body = new URLSearchParams({
    expr: document.getElementById("expr").value,
    row_block: (document.querySelector('[name=row_block]') || {}).value || ""
  });
  fetch("{{ route('admin.aturan.impact') }}", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-TOKEN": document.querySelector('meta[name=csrf-token]').content },
    body: body
  }).then(function (r) { return r.json(); }).then(function (d) {
    if (!d.ok) { out.className = "test-out flag error"; out.textContent = "Error: " + d.error; return; }
    out.className = "test-out " + (d.fire_docs ? "flag warning" : "flag");
    var pct = d.docs ? Math.round(100 * d.fire_docs / d.docs) : 0;
    var lines = "Diuji " + d.docs + " dokumen" + (d.truncated ? " (dibatasi 500)" : "") + "\n"
      + "MENYALA di " + d.fire_docs + " dokumen (" + pct + "%)"
      + (d.fire_rows !== d.fire_docs ? ", " + d.fire_rows + " baris" : "") + "\n"
      + (d.eval_errors ? d.eval_errors + " dokumen gagal dievaluasi\n" : "")
      + "\ncontoh dokumen:\n";
    d.samples.forEach(function (s) {
      lines += "  #" + s.doc_id + "  " + (s.wilayah || "") + "  " + s.ruta
        + (s.rows && s.rows.length ? "  baris: " + s.rows.join(", ") : "") + "\n";
    });
    out.textContent = lines;
  }).catch(function (e) { out.className = "test-out flag error"; out.textContent = e.message; });
});
</script>
@endpush
@endsection
