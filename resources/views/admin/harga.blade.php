@extends('layouts.app')
@php($nav = 'harga')
@section('title', 'Harga & Kalori Komoditas · Sistem SUSENAS')
@section('content')
<h1>Harga &amp; Kalori Wajar Komoditas</h1>
<p class="muted">Satu rentang harga per komoditas untuk aturan <span class="mono">IV1-HARGA-MAHAL</span> /
  <span class="mono">IV1-HARGA-MURAH</span> (harga satuan = nilai &divide; kuantitas harus di dalam rentang),
  dan <span class="mono">kalori</span> per satuan
  untuk aturan <span class="mono">KALORI-*</span>. Berlaku untuk <strong>seluruh sampel</strong> —
  versi kuesioner aktif <strong>{{ $activeQvCode }}</strong>.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <details>
    <summary style="cursor:pointer;font-weight:600">Impor massal dari Excel / CSV</summary>
    <form method="post" action="{{ route('admin.harga.import') }}" class="stack-form mt" enctype="multipart/form-data">
      @csrf
      <p class="muted small">Kolom wajib <span class="mono">code, harga_min, harga_max</span>; opsional <span class="mono">kalori</span>.
        Baris dicocokkan lewat <span class="mono">code</span> ke daftar di bawah.</p>
      <label>Unggah berkas <span class="mono">.xlsx</span> / <span class="mono">.csv</span>
        <input type="file" name="file" accept=".xlsx,.csv,.txt">
      </label>
      <label>Sheet (opsional, untuk .xlsx)
        <input type="text" name="sheet" placeholder="mis. HARGA">
      </label>
      <label>atau tempel data CSV
        <textarea name="data" rows="5" class="mono"
          placeholder="code,harga_min,harga_max,kalori&#10;002,11000,15000,3622&#10;075,1800,2600,"></textarea>
      </label>
      <button type="submit">Impor</button>
    </form>
  </details>
</section>

<section class="panel">
  <h2>Daftar komoditas ({{ $komoditas->count() }})</h2>
  <p class="muted small">Ubah nama / kode / angka di baris, lalu klik <em>Simpan</em>. Kolom <span class="mono">kode</span>
    = nomor rincian komoditas (mis. <span class="mono">002</span> = Beras). Menambah / menghapus komoditas
    dilakukan di <strong>Metadata → Blok IV.1</strong>.</p>

  <input type="search" id="kom-cari" placeholder="cari nama komoditas / kode" style="min-width:280px;margin:6px 0">

  @foreach ($komoditas as $k)
    <form method="post" action="{{ route('admin.harga.save') }}" id="hf{{ $k->id }}" hidden>
      @csrf<input type="hidden" name="row_id" value="{{ $k->id }}">
    </form>
  @endforeach

  <div class="tablewrap tall">
  <table class="tbl" id="kom-tabel">
    <thead><tr>
      <th style="width:74px">Kode</th>
      <th>Nama komoditas</th>
      <th style="width:110px" class="num">Harga min</th>
      <th style="width:110px" class="num">Harga max</th>
      <th style="width:100px" class="num">Kalori/satuan</th>
      <th style="width:80px"></th>
    </tr></thead>
    <tbody>
    @forelse ($komoditas as $k)
      <tr>
        <td><input form="hf{{ $k->id }}" name="code" value="{{ $k->code }}" class="mono" style="width:60px"></td>
        <td><input form="hf{{ $k->id }}" name="label" value="{{ $k->label }}" required style="width:100%;min-width:220px"></td>
        <td><input form="hf{{ $k->id }}" name="harga_min" value="{{ $k->ref['harga_min'] ?? '' }}" class="mono" inputmode="decimal" style="width:100px"></td>
        <td><input form="hf{{ $k->id }}" name="harga_max" value="{{ $k->ref['harga_max'] ?? '' }}" class="mono" inputmode="decimal" style="width:100px"></td>
        <td><input form="hf{{ $k->id }}" name="kalori" value="{{ $k->ref['kalori'] ?? '' }}" class="mono" inputmode="decimal" style="width:90px"></td>
        <td class="right"><button form="hf{{ $k->id }}" type="submit" class="btn-ghost">Simpan</button></td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted">Blok IV.1 belum punya daftar komoditas untuk versi {{ $activeQvCode }}.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
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
@endsection
