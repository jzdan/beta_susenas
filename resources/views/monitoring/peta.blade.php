@extends('layouts.app')
@php($nav = 'peta')
@section('title', 'Peta Progres · Sistem SUSENAS')
@section('content')
<h1>Peta Progres Pencacahan</h1>
<p class="muted">Skematik per kecamatan → desa → blok sensus. Warna = persen ruta selesai dicacah.
  Total: {{ $done }}/{{ $total }} ruta.</p>

<div class="legend">
  <span><i class="tile b0"></i> 0%</span>
  <span><i class="tile b1"></i> 1–49%</span>
  <span><i class="tile b50"></i> 50–89%</span>
  <span><i class="tile b90"></i> 90–99%</span>
  <span><i class="tile b100"></i> 100%</span>
</div>

@forelse ($kecs as $k)
<section class="panel">
  <div class="panel-head">
    <h2>{{ $k['name'] }} <span class="mono muted">{{ $k['code'] }}</span></h2>
    <span class="rate-badge {{ $k['bucket'] }}">{{ $k['done'] }}/{{ $k['total'] }} · {{ $k['rate'] }}%</span>
  </div>
  @forelse ($k['desa'] as $d)
  <div class="desa-row">
    <div class="desa-name">
      {{ $d['name'] }} <span class="mono muted">{{ $d['code'] }}</span>
      <span class="muted small">{{ $d['done'] }}/{{ $d['total'] }}</span>
    </div>
    <div class="bs-strip">
      @foreach ($d['bs'] as $b)
      <a class="tile lg {{ $b['bucket'] }}" href="{{ route('monitoring.samples', ['wil' => $d['code']]) }}"
         title="Blok Sensus {{ $b['bs'] }} — {{ $b['d'] }}/{{ $b['t'] }} ({{ $b['rate'] }}%)">
        <span>{{ $b['bs'] }}</span><small>{{ $b['d'] }}/{{ $b['t'] }}</small>
      </a>
      @endforeach
    </div>
  </div>
  @empty
  <p class="muted">Tidak ada sampel.</p>
  @endforelse
</section>
@empty
<section class="panel"><p class="muted">Belum ada sampel dalam cakupan Anda.
  @if(auth()->user()->role === 'admin')<a href="{{ route('admin.impor.sampel') }}">Impor daftar sampel</a>.@endif</p></section>
@endforelse
@endsection
