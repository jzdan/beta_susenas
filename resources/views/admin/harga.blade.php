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
  <form method="post" action="{{ route('admin.harga.import') }}" class="stack-form">
    @csrf
    <label>Siklus survei
      <select name="cycle_id" required>
        @foreach ($cycles as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->period }})</option>@endforeach
      </select>
    </label>
    <label>Data CSV — kolom: <span class="mono">code, wilayah_prefix, harga_min, harga_max</span>
      <textarea name="data" rows="8" class="mono" required
        placeholder="code,wilayah_prefix,harga_min,harga_max&#10;002,3526,11000,15000&#10;075,3526,1800,2600"></textarea>
    </label>
    <button type="submit">Impor</button>
  </form>
</section>

<section class="panel">
  <h2>Terdaftar ({{ $prices->count() }})</h2>
  <table class="tbl">
    <thead><tr><th>Versi</th><th>Kode</th><th>Wilayah</th><th class="num">Min</th><th class="num">Max</th><th></th></tr></thead>
    <tbody>
    @forelse ($prices as $p)
      <tr>
        <td>{{ $p->qversion?->code }}</td>
        <td class="mono">{{ $p->code }}</td>
        <td class="mono">{{ $p->wilayah_prefix ?: 'nasional' }}</td>
        <td class="num">{{ number_format($p->harga_min, 0, ',', '.') }}</td>
        <td class="num">{{ number_format($p->harga_max, 0, ',', '.') }}</td>
        <td class="right"><form method="post" action="{{ route('admin.harga.del', $p) }}">@csrf<button class="btn-ghost">Hapus</button></form></td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted">Belum ada — aturan harga memakai rentang bawaan daftar komoditas.</td></tr>
    @endforelse
    </tbody>
  </table>
</section>
@endsection
