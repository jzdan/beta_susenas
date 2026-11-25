@extends('layouts.app')
@php($nav = 'periode')
@section('title', $hubCycle->label().' TW'.$hubCycle->quarter().' '.$hubCycle->year().' · Sistem SUSENAS')
@section('content')
@include('periode._hub_header')

<div class="next-step">
  <div><strong>{{ $next['text'] }}</strong><div class="small">{{ $next['sub'] }}</div></div>
  @if($next['url'])<a class="btn-primary-link" href="{{ $next['url'] }}">{{ $next['btn'] }} →</a>@endif
</div>

<div class="kpis">
  <div class="kpi"><span class="n">{{ $st['total'] }}</span><span class="l">Total sampel</span></div>
  <div class="kpi"><span class="n">{{ $st['terisi'] }}</span><span class="l">Sampel terisi</span></div>
  <div class="kpi"><span class="n">{{ $st['selesai'] }}</span><span class="l">Selesai dicacah</span></div>
  <div class="kpi {{ $st['kesalahan'] ? 'bad' : '' }}"><span class="n">{{ $st['kesalahan'] }}</span><span class="l">Sampel ada kesalahan</span></div>
</div>

<div class="grid2">
  <section class="panel">
    <h2>Tahap sampel</h2>
    <div class="tablewrap">
    <table class="tbl"><tbody>
      @foreach (Tahap::LABELS as $k => $lbl)
        <tr><td><a href="{{ route('monitoring.samples', ['cycle' => $hubCycle->id, 'tahap' => $k]) }}"><span class="chip t-{{ $k }}">{{ $lbl }}</span></a></td>
          <td class="num">{{ $st['tahap'][$k] }}</td></tr>
      @endforeach
    </tbody></table>
    </div>
  </section>
  <section class="panel">
    <h2>Aturan tersering menyala</h2>
    @if($topRules->isEmpty())
      <p class="muted">Belum ada temuan.</p>
    @else
    <div class="tablewrap">
    <table class="tbl">
      <thead><tr><th>Pesan</th><th>Tingkat</th><th class="num">Sampel</th></tr></thead>
      <tbody>
      @foreach ($topRules as $r)
        <tr><td>{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s*\{[^}]*\}\s*/', ' … ', $r->message)), 90) }}</td>
          <td><span class="flag {{ $r->severity }}">{{ Istilah::severity($r->severity) }}</span></td>
          <td class="num"><a href="{{ route('pengolahan.index', ['cycle' => $hubCycle->id, 'rule' => $r->code]) }}">{{ $r->n_doc }}</a></td></tr>
      @endforeach
      </tbody>
    </table>
    </div>
    @endif
  </section>
</div>
@endsection
