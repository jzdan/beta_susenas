@extends('layouts.app')
@php($nav = 'aturan')
@section('title', 'Aturan Validasi · Sistem SUSENAS')
@section('content')
<h1>Aturan Validasi</h1>
<p class="muted">Pemeriksaan otomatis yang berjalan tiap dokumen disimpan.
  <strong>{{ $activeCount }}</strong> dari {{ $total }} aturan aktif.</p>
<p class="muted">Untuk pemakaian sehari-hari cukup di halaman ini: <strong>nyalakan/matikan</strong> aturan
  dan <strong>ubah angka ambang</strong>. Tulisan kode/ekspresi hanya untuk yang menyusun aturan
  (Admin/IPDS) — ada di <em>Mode lanjutan</em> paling bawah, tidak perlu dibuka untuk operasional biasa.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if ($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <form method="get" class="filters">
    <select name="sev">
      <option value="">semua tingkat</option>
      <option value="error" @selected($filters['sev'] === 'error')>error (blokir bersih)</option>
      <option value="warning" @selected($filters['sev'] === 'warning')>warning</option>
    </select>
    <select name="active">
      <option value="">aktif &amp; nonaktif</option>
      <option value="ya" @selected($filters['active'] === 'ya')>aktif saja</option>
      <option value="tidak" @selected($filters['active'] === 'tidak')>nonaktif saja</option>
    </select>
    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="cari kode / pesan" style="min-width:220px">
    <button type="submit">Filter</button>
    @if(array_filter($filters))<a class="btn-ghost" href="{{ route('admin.aturan') }}">Reset</a>@endif
    <span class="muted small">{{ $rules->count() }} aturan ditampilkan</span>
  </form>

  @foreach ($rules as $r)
    <form method="post" action="{{ route('admin.aturan.cepat', $r) }}" id="qc{{ $r->id }}" hidden>@csrf</form>
    <form method="post" action="{{ route('admin.aturan.toggle', $r) }}" id="tg{{ $r->id }}" hidden>@csrf</form>
  @endforeach

  <form method="post" action="{{ route('admin.aturan.toggle-massal') }}" id="bulk">@csrf</form>
  <div class="row-form" style="margin:10px 0">
    <label class="small"><input type="checkbox" id="rule-all"> pilih semua terlihat</label>
    <button form="bulk" type="submit" name="aksi" value="aktif" class="btn-ghost">aktifkan terpilih</button>
    <button form="bulk" type="submit" name="aksi" value="nonaktif" class="btn-ghost">nonaktifkan terpilih</button>
  </div>

  @forelse ($groups as $groupName => $groupRules)
    <details class="rule-group" open>
      <summary style="cursor:pointer;font-weight:600;padding:6px 0">
        {{ $groupName }}
        <span class="muted small">— {{ $groupRules->where('active', true)->count() }}/{{ $groupRules->count() }} aktif</span>
      </summary>
      <div class="tablewrap">
      <table class="tbl">
        <thead><tr>
          <th style="width:28px"></th><th style="width:70px">Tingkat</th>
          <th>Pesan yang tampil ke pemeriksa</th>
          <th style="width:260px">Keterangan / ambang</th>
          <th style="width:150px"></th>
        </tr></thead>
        <tbody>
        @foreach ($groupRules as $r)
          <tr class="{{ $r->active ? '' : 'row-off' }}">
            <td><input form="bulk" type="checkbox" class="rule-pick" name="ids[]" value="{{ $r->id }}"></td>
            <td>
              <span class="flag {{ $r->severity }}">{{ $r->severity }}</span>
              @if($ruleErrs[$r->id])<span class="flag error" title="sintaks: {{ $ruleErrs[$r->id] }}">!</span>@endif
            </td>
            <td>
              {{ $r->message }}
              <div class="mono muted small">{{ $r->code }}@if($r->target_var) · {{ $r->target_var }}@endif</div>
            </td>
            <td>
              <input form="qc{{ $r->id }}" name="note" value="{{ $r->note }}" placeholder="apa yang diperiksa aturan ini…"
                style="width:100%;font-size:.86rem">
              @if($r->soleThreshold() !== null)
                <label class="small muted">ambang
                  <input form="qc{{ $r->id }}" name="threshold" value="{{ $r->soleThreshold() }}"
                    inputmode="decimal" class="mono" style="width:88px">
                </label>
              @endif
            </td>
            <td class="right nowrap">
              <button form="qc{{ $r->id }}" type="submit" class="btn-ghost">Simpan</button>
              <button form="tg{{ $r->id }}" type="submit" class="btn-ghost">{{ $r->active ? 'matikan' : 'nyalakan' }}</button>
              <a class="btn-ghost" href="{{ route('admin.aturan.edit', $r) }}" title="ubah aturan (mode lanjutan)">⋯</a>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
      </div>
    </details>
  @empty
    <p class="muted">Tidak ada aturan yang cocok dengan filter.</p>
  @endforelse
</section>

<details class="panel" @if($edit) open @endif>
  <summary style="cursor:pointer;font-weight:600">Mode lanjutan — tulis &amp; uji ekspresi (untuk Admin/IPDS)</summary>

  <p class="muted small" style="margin-top:10px">Bagian ini untuk orang yang <em>menyusun</em> aturan. Petugas &amp; pemeriksa
    tidak perlu membukanya.</p>
  <p class="muted small">Konvensi: ekspresi bernilai <strong>TRUE = ada masalah</strong>.
    Fungsi: <span class="mono">blank filled num n coalesce abs round floor min max sum any_filled all_filled count_filled ymd</span>.
    <span class="mono">ymd("2026-03-10")</span> → 20260310.
    Metadata sampel: <span class="mono">sampel_wilayah · sampel_bs · sampel_ruta · sampel_krt</span>.
    Roster: <span class="mono">roster(var, idx) · roster_count(var, kode) · roster_all(var, kode) · roster_any(var, kode) · roster_first(nilaiVar, kunciVar, kode) · roster_max(var) · roster_sum(var)</span>.
    Isi <em>Row block</em> dengan kode blok berulang agar aturan dievaluasi per baris.</p>

  <form method="post" action="{{ route('admin.aturan.save') }}" class="stack-form">
    @csrf
    @if($edit)<input type="hidden" name="rule_id" value="{{ $edit->id }}">@endif
    <div class="row-form">
      <label>Kode <input name="code" value="{{ $edit->code ?? '' }}" required></label>
      <label>Tingkat
        <select name="severity">
          <option value="warning" @selected($edit && $edit->severity === 'warning')>warning</option>
          <option value="error" @selected($edit && $edit->severity === 'error')>error</option>
        </select>
      </label>
      <label>Scope <input name="scope" value="{{ $edit->scope ?? 'dokumen' }}"></label>
      <label>Target var <input name="target_var" value="{{ $edit->target_var ?? '' }}" class="mono" placeholder="opsional"></label>
      <label>Row block <input name="row_block" value="{{ $edit->row_block ?? '' }}" class="mono" placeholder="kosong = tingkat dokumen"></label>
    </div>
    <label>Ekspresi (TRUE = masalah)
      <input name="expr" id="expr" value="{{ $edit->expr ?? '' }}" class="mono" required>
    </label>
    <label>Pesan ke pemeriksa <input name="message" value="{{ $edit->message ?? '' }}" required></label>
    <label>Keterangan (opsional) <input name="note" value="{{ $edit->note ?? '' }}" placeholder="apa yang diperiksa aturan ini"></label>
    <div class="row-form">
      <button type="submit">Simpan</button>
      @if($edit)<a class="btn-ghost" href="{{ route('admin.aturan') }}">Batal</a>
      <button type="submit" formaction="{{ route('admin.aturan.del', $edit) }}"
        onclick="return confirm('Hapus aturan {{ $edit->code }}?')" class="btn-ghost">Hapus aturan</button>@endif
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
  </div>

  @if($rules->isNotEmpty())
  <details class="mt">
    <summary style="cursor:pointer;font-weight:600">Daftar semua ekspresi ({{ $rules->count() }}) — referensi teknis</summary>
    <div class="tablewrap tall mt">
    <table class="tbl">
      <thead><tr><th>Kode</th><th>Scope</th><th>Ekspresi</th><th></th></tr></thead>
      <tbody>
      @foreach ($rules as $r)
        <tr class="{{ $r->active ? '' : 'row-off' }}">
          <td class="mono small">{{ $r->code }}</td>
          <td class="small">{{ $r->scope }}@if($r->row_block)<span class="mono muted">/{{ $r->row_block }}</span>@endif</td>
          <td class="mono small">{{ $r->expr }}</td>
          <td class="right"><a class="btn-ghost" href="{{ route('admin.aturan.edit', $r) }}">Sunting</a></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  </details>
  @endif
</details>

@push('scripts')
<script>
(function () {
  var all = document.getElementById("rule-all");
  if (all) all.addEventListener("change", function () {
    document.querySelectorAll(".rule-pick").forEach(function (c) { c.checked = all.checked; });
  });

  var bt = document.getElementById("btn-test");
  if (bt) bt.addEventListener("click", function () {
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

  var bi = document.getElementById("btn-impact");
  if (bi) bi.addEventListener("click", function () {
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
})();
</script>
@endpush
@endsection
