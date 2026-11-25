@extends('layouts.app')
@php($nav = 'periode')
@section('title', 'Periode Survei · Sistem SUSENAS')
@section('content')
<h1>Periode Survei</h1>
<p class="muted">Pilih tahun. Setiap tahun berisi empat triwulan, dan tiap triwulan bisa berisi beberapa survei.</p>

@if(session('ok'))<div class="alert ok">{{ session('ok') }}</div>@endif

<div class="pgrid">
  @foreach ($years as $y)
  <a class="pcard" href="{{ route('periode.tahun', $y['year']) }}">
    <div class="pcard-head ph-tahun">
      <h3>{{ $y['year'] }}</h3>
      <p>{{ $y['surveys']->implode(' · ') }}</p>
      <span class="pbadge">{{ $y['quarters']->count() }} dari 4 triwulan</span>
    </div>
    <div class="pcard-body">
      @foreach ($y['quarters'] as $q => $cs)
        @php($qst = \App\Http\Controllers\PeriodeController::combinedStatus($cs))
        <div class="prow"><span>TW{{ $q }} · {{ $cs->pluck('survey')->unique()->implode(', ') }}</span>
          <span class="chip p-{{ $qst }}">{{ Istilah::cycleStatus($qst) }}</span></div>
      @endforeach
      @if($y['sum']['total'])
        <div class="bar mt"><i style="width: {{ round(100 * $y['sum']['terisi'] / $y['sum']['total']) }}%"></i></div>
        <div class="small muted">{{ $y['sum']['terisi'] }} dari {{ $y['sum']['total'] }} sampel terisi
          @if($y['sum']['kesalahan']) · <span style="color:var(--err)">{{ $y['sum']['kesalahan'] }} masih ada kesalahan</span>@endif</div>
      @endif
    </div>
    <div class="pcard-foot"><span>{{ $y['cycles']->count() }} kuesioner</span><span>Buka →</span></div>
  </a>
  @endforeach
  @if(auth()->user()->role === 'admin')
  <a class="pcard pcard-empty" href="{{ route('periode.tahun', $nextYear) }}">
    <div><b>+ Tahun {{ $nextYear }}</b>Siapkan triwulan-triwulannya</div>
  </a>
  @endif
</div>

@if(auth()->user()->role === 'admin')
<p class="small muted mt">Daftar semua siklus dalam satu tabel (ubah nama, tanggal, status):
  <a href="{{ route('admin.jadwal') }}">Kelola jadwal semua siklus →</a></p>
@endif
@endsection
