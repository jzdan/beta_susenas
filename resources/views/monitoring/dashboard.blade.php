@extends('layouts.app')
@php($nav = 'dash')
@section('title', 'Dashboard · Sistem SUSENAS')
@section('content')
<h1>Dashboard Monitoring
  @if($cycle)<small class="muted">TW{{ $cycle->quarter() }} {{ $cycle->year() }} · {{ $cycle->label() }}</small>@else<small class="muted">semua periode</small>@endif</h1>
@include('monitoring._cycle_pick')
<p class="muted">Cakupan sesuai peran &amp; wilayah Anda.</p>

<div class="kpis">
  <div class="kpi"><span class="n">{{ $total }}</span><span class="l">Total sampel</span></div>
  <div class="kpi"><span class="n">{{ $nDocs }}</span><span class="l">Sampel terisi</span></div>
  <div class="kpi"><span class="n">{{ $done }}</span><span class="l">Selesai dicacah</span></div>
  <div class="kpi"><span class="n">{{ $response['rate'] }}%</span><span class="l">Angka respons</span></div>
  <div class="kpi {{ $nErrDocs ? 'bad' : '' }}"><span class="n">{{ $nErrDocs }}</span><span class="l">Sampel ada kesalahan</span></div>
  <div class="kpi {{ $nWarnDocs ? 'warn' : '' }}"><span class="n">{{ $nWarnDocs }}</span><span class="l">Sampel ada peringatan</span></div>
</div>

<section class="panel">
  <h2>Hasil kunjungan</h2>
  <div class="summary-counts">
    <span class="cnt">{{ $response['responded'] }}</span> terespons ·
    <span class="cnt warn">{{ $response['nonresponse'] }}</span> non-respons ·
    <span class="cnt">{{ $response['pending'] }}</span> belum selesai ·
    <span class="muted">{{ $response['ineligible'] }} tidak memenuhi syarat (kosong/ganda)</span> ·
    <span class="muted">{{ $response['replaced'] }} diganti</span>
  </div>
  <p class="muted small">Angka respons = selesai ÷ {{ $response['eligible'] }} rumah tangga yang memenuhi syarat
    (sampel yang digantikan, bangunan kosong, dan rumah tangga ganda tidak dihitung).</p>
</section>

@if(!empty($schedule))
<section class="panel">
  <h2>Jadwal &amp; kecepatan pencacahan</h2>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr>
      <th>Periode</th><th>Jadwal pencacahan</th><th class="num">Hari tersisa</th>
      <th class="num">Target vs Riil</th><th>Progres</th><th class="num">Butuh/hari</th><th>Status</th>
    </tr></thead>
    <tbody>
    @foreach ($schedule as $s)
      @php($behind = $s['gap'] <= -5)
      <tr>
        <td>{{ $s['cycle'] }}</td>
        <td class="small">{{ $s['start'] }} – {{ $s['end'] }}<br>
          <span class="muted">hari ke-{{ $s['elapsed_days'] }} / {{ $s['total_days'] }}</span></td>
        <td class="num">{{ $s['phase'] === 'lewat' ? '—' : $s['days_left'] }}</td>
        <td class="num">{{ $s['expected'] }}% / <strong>{{ $s['actual'] }}%</strong></td>
        <td>
          <div class="bar"><i style="width: {{ $s['actual'] }}%"></i></div>
          <small class="{{ $behind ? 'flag error' : 'muted' }}">selisih {{ $s['gap'] > 0 ? '+' : '' }}{{ $s['gap'] }} poin</small>
        </td>
        <td class="num">{{ $s['remaining'] > 0 && $s['phase'] !== 'lewat' ? $s['need_per_day'] : '—' }}</td>
        <td>
          @if($s['phase'] === 'belum_mulai')<span class="chip">Belum mulai</span>
          @elseif($s['phase'] === 'lewat')<span class="chip s-selesai">Periode berakhir</span>
          @elseif($behind)<span class="chip s-belum">Tertinggal</span>
          @else<span class="chip s-selesai">Sesuai target</span>@endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  <p class="muted small">Target = proporsi hari pencacahan yang sudah berjalan. Tertinggal bila riil &lt; target − 5 poin.</p>
</section>
@endif

@if($topRules->isNotEmpty())
<section class="panel">
  <h2>Aturan tersering menyala</h2>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>Kode</th><th>Tingkat</th><th class="num">Sampel</th><th></th></tr></thead>
    <tbody>
    @foreach ($topRules as $tr)
      <tr>
        <td class="mono">{{ $tr->code }}</td>
        <td><span class="flag {{ $tr->severity === 'error' ? 'error' : 'warning' }}" style="display:inline-block">{{ Istilah::severity($tr->severity) }}</span></td>
        <td class="num">{{ $tr->n_doc }}</td>
        <td class="right">
          @if(in_array(auth()->user()->role, ['pml','pj','admin']))
          <a class="btn-ghost" href="{{ route('pengolahan.index', ['rule' => $tr->code]) }}">Lihat</a>
          @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
</section>
@endif

<div class="grid2">
  <section class="panel">
    <h2>Tahap sampel</h2>
    <div class="tablewrap">
    <table class="tbl">
      <thead><tr><th>Tahap</th><th class="num">Sampel</th><th>Proporsi</th></tr></thead>
      <tbody>
      @foreach (Tahap::LABELS as $k => $lbl)
        <tr>
          <td><a href="{{ route('monitoring.samples', ['tahap' => $k]) }}"><span class="chip t-{{ $k }}">{{ $lbl }}</span></a></td>
          <td class="num">{{ $tahap[$k] }}</td>
          <td><div class="bar"><i style="width: {{ $nAll ? round(100 * $tahap[$k] / $nAll, 1) : 0 }}%"></i></div></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  </section>

  <section class="panel">
    <h2>Progres per petugas</h2>
    <div class="tablewrap">
    <table class="tbl">
      <thead><tr><th>Petugas</th><th class="num">Selesai/total</th><th>Progres</th></tr></thead>
      <tbody>
      @foreach ($perPetugas as $r)
        <tr>
          <td>{{ $r['name'] }}</td>
          <td class="num">{{ $r['done'] }}/{{ $r['total'] }}</td>
          <td><div class="bar"><i style="width: {{ $r['rate'] }}%"></i></div> <small>{{ $r['rate'] }}%</small></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  </section>
</div>

<section class="panel">
  <h2>Progres per desa <small class="muted">(diurutkan dari terendah)</small></h2>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>Kecamatan</th><th>Desa</th><th class="num">Selesai/total</th><th>Progres</th></tr></thead>
    <tbody>
    @foreach ($perWil as $r)
      <tr>
        <td>{{ $r['kec'] }}</td>
        <td>{{ $r['name'] }} <span class="mono muted">{{ $r['code'] }}</span></td>
        <td class="num">{{ $r['done'] }}/{{ $r['total'] }}</td>
        <td><div class="bar"><i style="width: {{ $r['rate'] }}%"></i></div> <small>{{ $r['rate'] }}%</small></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
</section>
@endsection
