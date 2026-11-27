@extends('layouts.app')
@php($nav = 'peta')
@section('title', 'Peta Progres · Sistem SUSENAS')
@section('content')
<h1>Peta Progres Pencacahan
  @if($cycle)<small class="muted">TW{{ $cycle->quarter() }} {{ $cycle->year() }} · {{ $cycle->label() }}</small>@else<small class="muted">semua periode</small>@endif</h1>
@include('monitoring._cycle_pick')
<p class="muted">@if($cycle){{ $cycle->label() }} {{ $cycle->monthName() }} {{ $cycle->year() }} ({{ strtolower(Istilah::cycleStatus($cycle->status)) }}). @else Gabungan semua periode, jadi blok sensus yang sama bisa berisi sampel dari beberapa periode. @endif
  Warna = persen rumah tangga selesai dicacah per blok sensus.
  Total: {{ $done }}/{{ $total }} rumah tangga.</p>

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
      <a class="tile lg {{ $b['bucket'] }}" href="{{ route('monitoring.samples', ['wil' => $d['code'], 'cycle' => $cycle?->id]) }}"
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
