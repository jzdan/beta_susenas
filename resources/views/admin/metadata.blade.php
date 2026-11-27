@extends('layouts.app')
@php($nav = 'meta')
@section('title', 'Metadata Kuesioner · Sistem SUSENAS')
@section('content')
@if($qv->cycle)
@include('periode._hub_header', \App\Http\Controllers\PeriodeController::hubContext($qv->cycle) + ['hubTab' => 'kuesioner'])
@else
<h1>Metadata Kuesioner</h1>
@endif
<p class="muted">Menyunting versi <strong>{{ $qv->code }}</strong> (berlaku {{ $qv->effective_date }}).
  Untuk rincian dalam jumlah banyak, pakai <a href="{{ route('admin.impor', ['qv' => $qv->id]) }}">Impor Rincian (CSV)</a>.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

@unless($qv->cycle)
@include('admin.partials._qv_switch')
@endunless
@include('admin.partials._ro_version', ['roQv' => $qv])
@include('admin.partials._tech_toggle')

@if($vc = session('versiCheck'))
<section class="panel" style="border-color:{{ $vc['errors'] ? 'var(--err)' : 'var(--warn)' }}">
  <h2>Hasil pemeriksaan versi {{ $vc['code'] }}</h2>
  @if(!$vc['errors'] && !$vc['warnings'])
    <p style="color:var(--ok)">Tidak ada masalah. Semua aturan, agregat, turunan, dan parameter menunjuk blok &amp; variabel yang ada.</p>
  @elseif($vc['errors'])
    <p class="muted small">Nama yang tidak ditemukan menahan pembukaan pencacahan periode yang memakai versi ini.
      Perbaiki di Metadata atau Aturan Validasi.</p>
  @endif
  @if($vc['errors'])
    <h3>Nama yang tidak ditemukan ({{ count($vc['errors']) }})</h3>
    <ul>@foreach ($vc['errors'] as $e)<li class="small" style="color:var(--err)">{{ $e }}</li>@endforeach</ul>
  @endif
  @if($vc['warnings'])
    <h3>Peringatan ({{ count($vc['warnings']) }})</h3>
    <ul>@foreach ($vc['warnings'] as $w)<li class="small">{{ $w }}</li>@endforeach</ul>
  @endif
</section>
@endif

{{-- Inc 154: di dalam hub siklus, tabel ini hanya berisi versi milik siklus itu
     (dulu semua versi dari semua periode — membingungkan sejak Metadata jadi tab hub) --}}
@php($listVersions = $qv->cycle ? $versions->where('cycle_id', $qv->cycle_id) : $versions)
<section class="panel">
  <h2>{{ $qv->cycle ? 'Versi kuesioner periode ini' : 'Versi kuesioner' }}</h2>
  @foreach ($listVersions as $v)
    <form method="post" action="{{ route('admin.versi.simpan', $v) }}" id="vf{{ $v->id }}" hidden>@csrf</form>
  @endforeach
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th style="width:180px">Kode</th><th style="width:160px">Tanggal berlaku</th><th>Sampel</th><th></th><th></th></tr></thead>
    <tbody>
    @foreach ($listVersions as $v)
      <tr>
        <td><input form="vf{{ $v->id }}" name="code" value="{{ $v->code }}" class="mono" style="width:160px"></td>
        <td><input form="vf{{ $v->id }}" type="date" name="effective_date" value="{{ $v->effective_date }}"></td>
        <td class="nowrap">
          <strong>{{ $v->documents_count }}</strong> terisi
          <span class="muted">dari {{ $sampleTotals[$v->cycle_id] ?? 0 }}</span><br>
          <a class="small" href="{{ route('admin.versi.sampel', $v) }}">Rincian sampel →</a>
        </td>
        <td>
          @if($v->id === $activeId)<span class="chip d-bersih" title="Versi yang dibuka pertama kali di menu admin. Tidak memengaruhi petugas.">bawaan</span>@endif
          @if($v->id === $qv->id && $listVersions->count() > 1)<span class="chip d-draft">dilihat</span>@endif
        </td>
        <td class="right nowrap">
          <button form="vf{{ $v->id }}" type="submit" class="btn-ghost">Simpan</button>
          @if($v->id !== $qv->id)
            <a class="btn-ghost" href="{{ route('admin.metadata', ['qv' => $v->id]) }}">Lihat/sunting</a>
          @endif
          @if($v->id !== $activeId)
            <form method="post" action="{{ route('admin.versi.aktifkan', $v) }}" class="ro-allow">@csrf<button class="btn-ghost" title="Buka versi ini pertama kali di menu admin. Tidak memengaruhi petugas.">Jadikan versi utama</button></form>
          @endif
          <form method="post" action="{{ route('admin.versi.periksa', $v) }}" class="ro-allow"
            title="Cek aturan, agregat, turunan & parameter yang menunjuk blok/variabel yang tidak ada">@csrf<button class="btn-ghost">Periksa</button></form>
          <a class="btn-ghost" href="{{ route('admin.versi.bandingkan', $v) }}" title="Apa saja yang berbeda dari triwulan sebelumnya">Bandingkan</a>
          <a class="btn-ghost" href="{{ route('admin.versi.codebook', $v) }}">Buku kode</a>
          <a class="btn-ghost" href="{{ route('admin.pratinjau', ['qv' => $v->id]) }}">Pratinjau entri</a>
          <a class="btn-ghost" href="{{ route('admin.versi.export', $v) }}">Ekspor JSON</a>
          @if($v->documents_count === 0 && $versions->count() > 1)
            <form method="post" action="{{ route('admin.versi.hapus', $v) }}"
              onsubmit="return confirm('Hapus versi {{ $v->code }} beserta semua blok, rincian, dan aturannya? Tidak bisa dibatalkan.')">
              @csrf<button class="btn-ghost" style="color:var(--err)">Hapus</button></form>
          @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  @if($qv->cycle)
  <p class="muted small mt">Untuk triwulan berikutnya, kuesioner ini disalin lewat
    <a href="{{ route('periode.tahun', $qv->cycle->year()) }}">Periode Survei → "+ Siapkan triwulan ini"</a>.</p>
  @else
  <form method="post" action="{{ route('admin.versi.duplikat') }}" class="row-form mt ro-allow">
    @csrf
    <input name="new_code" placeholder="Kode versi baru, mis. VSEN27.KP-rev2" required style="min-width:280px">
    <button type="submit">Duplikasi versi utama ({{ $versions->firstWhere('id', $activeId)->code ?? '?' }})</button>
  </form>
  @endif
  <p class="muted small mt">Paket JSON berisi seluruh metadata versi (blok, rincian, opsi, parameter,
    aturan, agregat, nilai turunan, baris tetap).
    <a href="{{ route('admin.versi.import') }}">Impor paket dari instansi lain →</a></p>
</section>

<section class="panel">
  <h2>Tambah blok</h2>
  <form method="post" action="{{ route('admin.blok.add') }}" class="row-form">
    @csrf
    <input type="hidden" name="qv" value="{{ $qv->id }}">
    <input name="code" placeholder="Kode (mis. IV.3)" required>
    <input name="title" placeholder="Judul blok (mis. Konsumsi Rokok)" required style="min-width:min(290px, 100%)">
    <select name="repeat" id="blok-tipe" aria-describedby="tipe-help">
      <option value="ruta">Diisi sekali per rumah tangga (mis. Blok I)</option>
      <option value="art">Diisi per anggota rumah tangga (mis. umur, pendidikan)</option>
      <option value="list">Diisi per item daftar tetap (mis. komoditas IV.1)</option>
      <option value="list_art">Diisi per item daftar × per anggota rumah tangga (mis. makanan jadi IV.1M)</option>
    </select>
    <button type="submit">Tambah</button>
  </form>

  {{-- sketsa bentuk blok per tipe; yang tampil ikut pilihan dropdown (JS di bawah) --}}
  <div class="tipe-help" id="tipe-help" aria-live="polite">
    <div class="tipe-opt" data-tipe="ruta">
      <div class="sk">
        <div class="sk-card">Nama kepala rumah tangga<i></i></div>
        <div class="sk-card">Alamat<i></i></div>
      </div>
      <p class="tipe-txt">Tiap rincian diisi <strong>sekali</strong> untuk satu rumah tangga — tampil sebagai kartu isian.
        Contoh: Blok I Keterangan Tempat, keterangan perumahan.<br>
        <span class="muted">Setelah dibuat: tambah rinciannya.</span></p>
    </div>
    <div class="tipe-opt" data-tipe="art" hidden>
      <table class="sk sk-tbl"><tr><th>Anggota</th><th>Umur</th><th>Pendidikan</th></tr>
        <tr><td>1 · Budi</td><td></td><td></td></tr><tr><td>2 · Siti</td><td></td><td></td></tr><tr><td>3 · …</td><td></td><td></td></tr></table>
      <p class="tipe-txt">Tiap rincian diisi <strong>sekali per anggota rumah tangga</strong> — 1 baris = 1 orang.
        Jumlah baris mengikuti isian banyaknya anggota rumah tangga.<br>
        <span class="muted">Setelah dibuat: tambah rincian (jadi kolom tabel).</span></p>
    </div>
    <div class="tipe-opt" data-tipe="list" hidden>
      <table class="sk sk-tbl"><tr><th>Komoditas</th><th>Banyaknya</th><th>Nilai (Rp)</th></tr>
        <tr><td>Beras</td><td></td><td></td></tr><tr><td>Telur ayam</td><td></td><td></td></tr><tr><td>Minyak goreng</td><td></td><td></td></tr></table>
      <p class="tipe-txt">Tiap rincian diisi <strong>sekali per item</strong> dari daftar yang sudah ditentukan — 1 baris = 1 item.
        Contoh: IV.1 (194 komoditas), IV.2 (102 item bukan makanan).<br>
        <span class="muted">Setelah dibuat: tambah rincian (kolom), lalu isi daftar barisnya (bisa impor CSV/Excel).</span></p>
    </div>
    <div class="tipe-opt" data-tipe="list_art" hidden>
      <table class="sk sk-tbl"><tr><th>Item × anggota</th><th>Banyaknya</th><th>Nilai (Rp)</th></tr>
        <tr><td>Nasi bungkus · Budi</td><td></td><td></td></tr><tr><td>Nasi bungkus · Siti</td><td></td><td></td></tr><tr><td>Roti · Budi</td><td></td><td></td></tr></table>
      <p class="tipe-txt">Tiap item daftar diisi <strong>untuk tiap anggota rumah tangga</strong>.
        Contoh: IV.1M makanan jadi / Makan Bergizi Gratis per orang.<br>
        <span class="muted">Setelah dibuat: tambah rincian, lalu isi daftar barisnya.</span></p>
    </div>
    <p class="tipe-warn">Tipe tidak bisa diubah lagi setelah blok punya isian. Kalau salah, buat blok baru.</p>
  </div>
</section>

@push('scripts')
<style>
  .tipe-help { margin-top: 12px; padding: 12px 14px; background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--radius); }
  .tipe-opt { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
  .tipe-opt[hidden] { display: none; }
  .tipe-txt { flex: 1; min-width: 220px; margin: 0; font-size: .88rem; color: var(--ink-soft); }
  .tipe-warn { margin: 10px 0 0; font-size: .82rem; color: var(--warn); }
  .sk { flex: none; font-size: .7rem; color: var(--muted); }
  div.sk { display: grid; gap: 4px; }
  .sk-card { width: 150px; padding: 4px 7px; border: 1px solid var(--line-strong); border-radius: 4px; background: var(--surface); }
  .sk-card i { display: block; height: 9px; margin-top: 3px; border: 1px solid var(--line-strong); border-radius: 2px; }
  .sk-tbl { border-collapse: collapse; background: var(--surface); }
  .sk-tbl th, .sk-tbl td { border: 1px solid var(--line-strong); padding: 2px 6px; height: 17px; min-width: 34px; text-align: left; font-weight: 400; white-space: nowrap; }
  .sk-tbl th { background: var(--paper); font-weight: 600; }
</style>
<script>
(function () {
  var sel = document.getElementById('blok-tipe');
  if (!sel) return;
  function show() {
    document.querySelectorAll('#tipe-help [data-tipe]').forEach(function (el) { el.hidden = el.dataset.tipe !== sel.value; });
  }
  sel.addEventListener('change', show);
  show();
})();
</script>
@endpush

<p class="muted small">Aturan, neraca, turunan, serta harga &amp; kalori ada di menu
  <a href="{{ route('admin.aturan', ['qv' => $qv->id]) }}">Aturan Validasi</a>.</p>

@foreach ($blocks as $i => $b)
<section class="panel">
  <div class="panel-head">
    <h2>Blok {{ $b->code }} — {{ $b->title }}
      <small class="muted">{{ Istilah::repeat($b->repeat) }}</small>
      @if($b->harga_kalori)<span class="chip d-selesai" title="Daftar komoditas blok ini disunting di Aturan Validasi → Harga & Kalori">harga &amp; kalori</span>@endif</h2>
    @php($lk = $links['byBlock'][$b->code] ?? ['rules' => 0, 'aggregates' => 0, 'derived' => 0])
    <p class="link-line small">Terhubung:
      @if($lk['rules'] + $lk['aggregates'] + $lk['derived'] === 0)
        <span class="muted">belum dipakai aturan, agregat, maupun turunan.</span>
      @else
        <a href="{{ route('admin.aturan', ['qv' => $qv->id, 'blok' => $b->code]) }}">{{ $lk['rules'] }} aturan</a> ·
        <a href="{{ route('admin.aturan', ['qv' => $qv->id, 'tab' => 'neraca', 'blok' => $b->code]) }}">{{ $lk['aggregates'] }} agregat · {{ $lk['derived'] }} turunan</a>
      @endif
    </p>
    <span>
      <form method="post" action="{{ route('admin.blok.order', $b) }}" style="display:inline">
        @csrf
        <button class="btn-ghost" name="arah" value="naik" @disabled($i === 0) title="naikkan">↑</button>
        <button class="btn-ghost" name="arah" value="turun" @disabled($loop->last) title="turunkan">↓</button>
      </form>
      <a class="btn-ghost" href="{{ route('admin.blok', $b) }}">Sunting blok</a>
      <a class="btn-ghost" href="{{ route('admin.pratinjau', ['qv' => $qv->id]) }}#blok-{{ $b->code }}"
         title="Lihat tampilan petugas untuk kuesioner ini">Pratinjau</a>
      <form method="post" action="{{ route('admin.blok.del', $b) }}" style="display:inline" onsubmit="return confirm('Hapus blok {{ $b->code }}?')">
        @csrf<button class="btn-ghost">Hapus</button>
      </form>
    </span>
  </div>
  @if($b->isRowBlock())
  {{-- Blok baris tetap (mis. komoditas IV.1): dua lapis — kolom isian (pertanyaan, diisi tiap baris)
       dan baris/rincian (block_rows). Dulu barisnya tidak tampil di sini sama sekali. --}}
  @php($rowList = $b->rows->sortBy('row_index'))
  <div class="row-block-rows">
    <h3>Baris / rincian <span class="muted small">({{ $rowList->count() }})</span></h3>
    @if($rowList->isEmpty())
      <p class="muted small">Belum ada baris. Tambahkan di <a href="{{ route('admin.blok', $b) }}">Sunting blok</a> (satu per satu atau impor CSV).</p>
    @else
      <div class="pinst">
        @foreach ($rowList->take(30) as $row)<span>{{ $row->label }}</span>@endforeach
        @if($rowList->count() > 30)<span class="muted">+{{ $rowList->count() - 30 }} lagi</span>@endif
      </div>
      <p class="small mt">
        <a href="{{ route('admin.blok', $b) }}">Kelola baris (tambah, ubah, impor CSV) →</a>
        @if($b->harga_kalori) · <a href="{{ route('admin.aturan', ['qv' => $qv->id, 'tab' => 'harga']) }}">Patokan harga &amp; kalori →</a>@endif
      </p>
    @endif
  </div>
  <h3>Kolom isian <span class="muted small">(diisi untuk setiap baris)</span></h3>
  @endif
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>{{ $b->isRowBlock() ? 'Kolom' : 'Rincian' }}</th><th class="tech">Variabel</th><th>Label</th><th>Tipe</th><th class="tech">Lewati bila</th><th></th></tr></thead>
    <tbody>
    @foreach ($b->questions->sortBy('order') as $q)
      <tr>
        <td class="mono">{{ $q->code_rincian }}</td>
        <td class="mono tech">{{ $q->var_name }}</td>
        <td>{{ $q->label }}</td>
        <td>{{ Istilah::qtype($q->qtype) }}@if($q->unit) · {{ $q->unit }}@endif</td>
        <td class="mono muted small tech">{{ $q->skip_expr ?: '—' }}</td>
        <td class="right"><a class="btn-ghost" href="{{ route('admin.rincian', $q) }}">Sunting</a></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
</section>
@endforeach
@endsection
