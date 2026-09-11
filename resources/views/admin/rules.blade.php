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
  <p class="muted small" style="margin:-4px 0 12px">
    Pada pesan aturan, tulisan dalam kurung kurawal diisi otomatis saat pemeriksaan:
    <span class="mono">{nama_kolom}</span> → nilai kolom itu, <span class="mono">{=rumus}</span> → hasil hitung
    (mis. <span class="mono">{=n(iv1_nilai_beli)/n(iv1_kuantitas)}</span> = harga satuan).
  </p>

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

<details class="panel">
  <summary style="cursor:pointer;font-weight:600">Kamus variabel — apa saja yang bisa dipakai di aturan</summary>
  <p class="muted small" style="margin-top:10px">Membuat aturan baru dari nol <strong>jarang</strong> dilakukan di kabupaten —
    biasanya aturan datang dari paket Sidona BPS atau disiapkan Seksi Sosial/IPDS. Kalau memang perlu,
    ini daftar variabel yang tersedia. Contoh <span class="mono">IDENT-BS</span>
    (<span class="mono">filled(b1_no_bs) and b1_no_bs != sampel_bs</span>) = pakai jenis
    <em>"Dua rincian harus sama / berbeda"</em>, rincian A = nomor BS di Blok I, hubungan = harus SAMA,
    rincian B = <span class="mono">sampel_bs</span>.</p>

  <input type="search" id="kamus-cari" placeholder="cari nama variabel / keterangan" style="min-width:260px;margin:6px 0">

  <div class="tablewrap tall">
  <table class="tbl" id="kamus-tabel">
    <thead><tr><th style="width:220px">Nama</th><th style="width:170px">Dari</th><th>Keterangan</th></tr></thead>
    <tbody>
      <tr><td class="mono">sampel_wilayah</td><td>Metadata sampel</td><td>Kode wilayah (desa) di daftar sampel/DSRT</td></tr>
      <tr><td class="mono">sampel_bs</td><td>Metadata sampel</td><td>Nomor blok sensus di daftar sampel</td></tr>
      <tr><td class="mono">sampel_ruta</td><td>Metadata sampel</td><td>Nomor urut rumah tangga sampel</td></tr>
      <tr><td class="mono">sampel_krt</td><td>Metadata sampel</td><td>Nama kepala rumah tangga di daftar sampel</td></tr>
      @foreach ($wizardBlocks as $bl)
        @foreach ($bl->questions as $q)
          <tr><td class="mono">{{ $q->var_name }}</td><td>Blok {{ $bl->code }} · {{ $q->code_rincian }}</td><td>{{ $q->label }}</td></tr>
        @endforeach
      @endforeach
      @foreach ($derivedFields as $df)
        <tr><td class="mono">{{ $df->var_name }}</td><td>Nilai turunan</td><td>{{ $df->label }} — dihitung sistem, jangan diisi</td></tr>
      @endforeach
      @foreach ($ruleParams as $p)
        <tr><td class="mono">{{ $p->name }}</td><td>Parameter</td><td>Nilai tetap ({{ $p->value }}){{ $p->label ? ' — '.$p->label : '' }}</td></tr>
      @endforeach
      @foreach ($refKeys as $k)
        <tr><td class="mono">ref_{{ $k }}</td><td>Referensi baris</td><td>Data <span class="mono">{{ $k }}</span> baris komoditas — <strong>hanya di aturan per-baris</strong> (blok tipe daftar)</td></tr>
      @endforeach
      @if($hasRoster)
        <tr><td class="mono">&lt;var&gt;__n</td><td>Roster (blok per-ART)</td><td>Jumlah ART yang mengisi &lt;var&gt;. Juga: <span class="mono">__sum __count1 __count2 __max __min</span></td></tr>
        <tr><td class="mono">art_terisi</td><td>Roster</td><td>Jumlah baris ART yang terisi di roster</td></tr>
        <tr><td class="mono">roster_count(var, kode)</td><td>Fungsi roster</td><td>Berapa ART yang nilai <span class="mono">var</span>-nya = kode. Juga <span class="mono">roster_any / roster_all / roster_max / roster_sum / roster_first</span></td></tr>
      @endif
    </tbody>
  </table>
  </div>

  <p class="muted small">Fungsi ekspresi: <span class="mono">blank(x)</span> = x kosong · <span class="mono">filled(x)</span> = x terisi ·
    <span class="mono">n(x)</span> = angka dari x · <span class="mono">abs round floor min max sum coalesce</span> ·
    <span class="mono">ymd("2026-03-10")</span> → 20260310 (bandingkan tanggal).</p>
</details>

<details class="panel" @if($errors->has('wizard') || old('kind')) open @endif>
  <summary style="cursor:pointer;font-weight:600">Buat aturan baru — form terpandu</summary>
  <p class="muted small" style="margin-top:10px">Pilih dari daftar, isi angka/pesan. Sistem yang menyusun ekspresinya.
    Belum tahu nama rincian? Buka <em>Kamus variabel</em> di atas. Untuk pemeriksaan rumit
    (neraca, rekap antar-blok) pakai <em>Mode lanjutan</em>.</p>
  @if($errors->has('wizard'))<div class="alert err">{{ $errors->first('wizard') }}</div>@endif

  <form method="post" action="{{ route('admin.aturan.terpandu') }}" class="stack-form" id="wizform">
    @csrf
    <div class="row-form">
      <label>Kode aturan <input name="code" value="{{ old('code') }}" placeholder="mis. IV1-BERAS-WAJIB" required></label>
      <label>Tingkat
        <select name="severity">
          <option value="warning" @selected(old('severity')==='warning')>warning (perlu ditinjau)</option>
          <option value="error" @selected(old('severity')==='error')>error (blokir bersih)</option>
        </select>
      </label>
      <label>Berlaku pada
        <select name="block">
          <option value="">Seluruh dokumen</option>
          @foreach ($wizardBlocks as $bl)
            <option value="{{ $bl->code }}" @selected(old('block')===$bl->code)>Blok {{ $bl->code }} — {{ $bl->title }}</option>
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

    <label>Pesan yang tampil ke pemeriksa
      <input name="message" value="{{ old('message') }}" placeholder="mis. Nilai beras terisi tetapi kuantitas kosong." required>
    </label>
    <label>Keterangan (opsional)
      <input name="note" value="{{ old('note') }}" placeholder="apa yang diperiksa aturan ini">
    </label>
    <div><button type="submit">Buat aturan</button></div>
  </form>
</details>

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
<style>
  .wiz-part { display: none; margin: 4px 0 6px; padding: 10px 12px; border-left: 3px solid var(--line-strong); }
  .wiz-part.on { display: block; }
</style>
<script>
(function () {
  var kc = document.getElementById("kamus-cari");
  if (kc) kc.addEventListener("input", function () {
    var q = kc.value.toLowerCase().trim();
    document.querySelectorAll("#kamus-tabel tbody tr").forEach(function (tr) {
      tr.hidden = q !== "" && tr.textContent.toLowerCase().indexOf(q) === -1;
    });
  });

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
