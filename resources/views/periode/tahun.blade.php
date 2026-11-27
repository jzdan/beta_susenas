@extends('layouts.app')
@php($nav = 'periode')
@section('title', "Periode {$tahun} · Sistem SUSENAS")
@section('content')
<a class="back-link" href="{{ route('periode.index') }}">← Kembali ke Periode Survei</a>
<div class="crumb"><a href="{{ route('periode.index') }}">Periode</a> › {{ $tahun }}</div>
<h1>{{ $tahun }}</h1>
<p class="muted legend-dots">Warna pita:
  <span><i class="lg-dot ph-persiapan"></i>persiapan</span>
  <span><i class="lg-dot ph-lapangan"></i>pencacahan</span>
  <span><i class="lg-dot ph-pengolahan"></i>pengolahan</span>
  <span><i class="lg-dot ph-selesai"></i>selesai</span></p>

<div class="qlist">
  @foreach ($quarters as $q => $info)
    @php($months = \App\Models\SurveyCycle::MONTHS[$q * 3 - 2].'–'.\App\Models\SurveyCycle::MONTHS[$q * 3])
    @if($info['cycles']->isEmpty())
      <a class="qrow qrow-empty" href="{{ route('periode.triwulan', [$tahun, $q]) }}">
        <div class="qband"><b>TW{{ $q }}</b><span>{{ $months }}</span></div>
        <div class="qbody">
          <span class="muted">Belum disiapkan.</span>
          @if($info['expected']->isNotEmpty())
            <span class="small muted">Biasanya: {{ $info['expected']->map(fn ($p) => trim(str_replace('|', ' ', $p)))->implode(', ') }}</span>
          @endif
        </div>
        <div class="qact">@if(auth()->user()->role === 'admin')<span class="btn-like">+ Siapkan triwulan ini</span>@endif</div>
      </a>
    @else
      <a class="qrow" href="{{ route('periode.triwulan', [$tahun, $q]) }}">
        <div class="qband ph-{{ $info['status'] }}">
          <b>TW{{ $q }}</b>
          <span>{{ $info['cycles']->map(fn ($c) => $c->monthName())->unique()->implode(', ') }} {{ $tahun }}</span>
          <span class="pbadge">{{ Istilah::cycleStatus($info['status']) }}</span>
        </div>
        <div class="qbody">
          <div class="pinst">@foreach ($info['cycles'] as $c)<span>{{ $c->label() }}</span>@endforeach</div>
          @if($info['sum']['total'])
            <div class="qstats">
              <span><b>{{ $info['sum']['terisi'] }}</b>/{{ $info['sum']['total'] }} sampel terisi</span>
              <span class="bar"><i style="width: {{ round(100 * $info['sum']['terisi'] / $info['sum']['total']) }}%"></i></span>
              <span><b>{{ $info['sum']['diperiksa'] }}</b> menunggu pemeriksaan</span>
              @if($info['sum']['kesalahan'])<span style="color:var(--err)"><b>{{ $info['sum']['kesalahan'] }}</b> masih ada kesalahan</span>@endif
            </div>
          @else
            <span class="small muted">Belum ada sampel.</span>
          @endif
        </div>
        <div class="qact"><span class="small">{{ $info['cycles']->count() }} kuesioner</span><span class="qopen">Buka →</span></div>
      </a>
    @endif
  @endforeach
</div>
@endsection
