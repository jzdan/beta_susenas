@extends('layouts.app')
@php($nav = 'harga')
@section('title', 'Harga Referensi · Sistem SUSENAS')
@section('content')
<h1>Rentang Harga Wajar Komoditas per Wilayah</h1>
<p class="muted">Menimpa <span class="mono">harga_min</span>/<span class="mono">harga_max</span> bawaan
  daftar komoditas (Blok IV.1) untuk aturan <span class="mono">IV1-HARGA</span>.
  Prefiks wilayah terpanjang yang cocok dengan wilayah sampel menang;
  <span class="mono">wilayah_prefix</span> kosong = berlaku nasional.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <form method="post" action="{{ route('admin.harga.import') }}" class="stack-form" enctype="multipart/form-data">
    @csrf
    <label>Siklus survei
      <select name="cycle_id" required>
        @foreach ($cycles as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->period }})</option>@endforeach
      </select>
    </label>
    <label>Unggah berkas Excel / CSV — kolom: <span class="mono">code, wilayah_prefix, harga_min, harga_max</span>
      <input type="file" name="file" accept=".xlsx,.csv,.txt">
    </label>
    <label>Sheet (opsional, untuk .xlsx — kosong = sheet pertama)
      <input type="text" name="sheet" placeholder="mis. HARGA">
    </label>
    <label>atau tempel data CSV
      <textarea name="data" rows="6" class="mono"
        placeholder="code,wilayah_prefix,harga_min,harga_max&#10;002,3526,11000,15000&#10;075,3526,1800,2600"></textarea>
    </label>
    <button type="submit">Impor</button>
  </form>

  <details class="mt">
    <summary style="cursor:pointer;font-weight:600">Daftar kode komoditas Blok IV.1 ({{ $komoditas->count() }}) — {{ $activeQvCode }}</summary>
    <p class="muted small" style="margin-top:8px">Pakai kolom <span class="mono">code</span> ini saat menyusun berkas impor.
      <span class="mono">Min/Max bawaan</span> = rentang harga default yang dipakai kalau tidak ada override wilayah.</p>
    <input type="search" id="kom-cari" placeholder="cari nama komoditas / kode" style="min-width:260px;margin:6px 0">
    <div class="tablewrap tall">
    <table class="tbl" id="kom-tabel">
      <thead><tr><th style="width:64px">Kode</th><th>Komoditas</th><th class="num">Min bawaan</th><th class="num">Max bawaan</th></tr></thead>
      <tbody>
      @foreach ($komoditas as $k)
        <tr>
          <td class="mono">{{ $k->code }}</td>
          <td>{{ $k->label }}</td>
          <td class="num">{{ isset($k->ref['harga_min']) ? number_format((float) $k->ref['harga_min'], 0, ',', '.') : '—' }}</td>
          <td class="num">{{ isset($k->ref['harga_max']) ? number_format((float) $k->ref['harga_max'], 0, ',', '.') : '—' }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  </details>
</section>

@push('scripts')
<script>
(function () {
  var c = document.getElementById("kom-cari");
  if (!c) return;
  c.addEventListener("input", function () {
    var q = c.value.toLowerCase().trim();
    document.querySelectorAll("#kom-tabel tbody tr").forEach(function (tr) {
      tr.hidden = q !== "" && tr.textContent.toLowerCase().indexOf(q) === -1;
    });
  });
})();
</script>
@endpush

<section class="panel">
  <h2>Terdaftar ({{ $prices->count() }})</h2>
  <p class="muted small">Kolom <span class="mono">code</span> = nomor rincian komoditas di Blok IV.1 (mis. <span class="mono">002</span> = Beras).
    Cari kode di menu <strong>Metadata → Blok IV.1</strong> atau di file daftar komoditas.</p>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>Versi</th><th style="width:64px">Kode</th><th>Komoditas</th><th>Wilayah</th><th class="num">Min</th><th class="num">Max</th><th></th></tr></thead>
    <tbody>
    @forelse ($prices as $p)
      <tr>
        <td>{{ $p->qversion?->code }}</td>
        <td class="mono">{{ $p->code }}</td>
        <td>{{ $labels[$p->qversion_id.':'.$p->code] ?? '— (kode tidak ada di daftar komoditas)' }}</td>
        <td class="mono">{{ $p->wilayah_prefix ?: 'nasional' }}</td>
        <td class="num">{{ number_format($p->harga_min, 0, ',', '.') }}</td>
        <td class="num">{{ number_format($p->harga_max, 0, ',', '.') }}</td>
        <td class="right"><form method="post" action="{{ route('admin.harga.del', $p) }}">@csrf<button class="btn-ghost">Hapus</button></form></td>
      </tr>
    @empty
      <tr><td colspan="7" class="muted">Belum ada — aturan harga memakai rentang bawaan daftar komoditas.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
