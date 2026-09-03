@extends('layouts.app')
@php($nav = 'dash')
@section('title', 'Dashboard · Sistem SUSENAS')
@section('content')
<h1>Dashboard Monitoring</h1>
<p class="muted">Cakupan sesuai peran &amp; wilayah Anda.</p>

<div class="kpis">
  <div class="kpi"><span class="n">{{ $total }}</span><span class="l">Ruta sampel aktif</span></div>
  <div class="kpi"><span class="n">{{ $done }}</span><span class="l">Selesai dicacah</span></div>
  <div class="kpi"><span class="n">{{ $response['rate'] }}%</span><span class="l">Response rate (eligible)</span></div>
  <div class="kpi {{ $nErrDocs ? 'bad' : '' }}"><span class="n">{{ $nErrDocs }}</span><span class="l">Dokumen ada error</span></div>
  <div class="kpi {{ $nWarnDocs ? 'warn' : '' }}"><span class="n">{{ $nWarnDocs }}</span><span class="l">Dokumen ada warning</span></div>
</div>

<section class="panel">
  <h2>Respons &amp; disposisi</h2>
  <div class="summary-counts">
    <span class="cnt">{{ $response['responded'] }}</span> terespons ·
    <span class="cnt warn">{{ $response['nonresponse'] }}</span> non-respons ·
    <span class="cnt">{{ $response['pending'] }}</span> belum selesai ·
    <span class="muted">{{ $response['ineligible'] }} tidak eligible (kosong/ganda)</span> ·
    <span class="muted">{{ $response['replaced'] }} diganti</span>
  </div>
  <p class="muted small">Response rate = selesai ÷ {{ $response['eligible'] }} ruta eligible
    (sampel yang digantikan &amp; kasus tidak-eligible dikeluarkan dari penyebut).</p>
</section>

@if(!empty($schedule))
<section class="panel">
  <h2>Jadwal &amp; kecepatan pencacahan</h2>
  <table class="tbl">
    <thead><tr>
      <th>Siklus</th><th>Periode lapangan</th><th class="num">Hari tersisa</th>
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
  <p class="muted small">Target = proporsi hari lapangan yang sudah berjalan. Tertinggal bila riil &lt; target − 5 poin.</p>
</section>
@endif

@if(collect($funnel)->sum('n') > 0)
<section class="panel">
  <h2>Alur pengolahan dokumen</h2>
  <table class="tbl">
    <thead><tr><th>Tahap</th><th class="num">Dokumen</th><th></th></tr></thead>
    <tbody>
    @php($fTot = max(1, collect($funnel)->sum('n')))
    @foreach ($funnel as $f)
      <tr>
        <td>{{ $f['label'] }}</td>
        <td class="num">{{ $f['n'] }}</td>
        <td><div class="bar"><i style="width: {{ round(100 * $f['n'] / $fTot, 1) }}%"></i></div></td>
      </tr>
    @endforeach
    </tbody>
  </table>

  @if($topRules->isNotEmpty())
  <h3>Aturan tersering menyala</h3>
  <table class="tbl">
    <thead><tr><th>Kode</th><th>Tingkat</th><th class="num">Dokumen</th><th></th></tr></thead>
    <tbody>
    @foreach ($topRules as $tr)
      <tr>
        <td class="mono">{{ $tr->code }}</td>
        <td><span class="flag {{ $tr->severity === 'error' ? 'error' : 'warning' }}" style="display:inline-block">{{ $tr->severity }}</span></td>
        <td class="num">{{ $tr->n_doc }}</td>
        <td class="right">
          @if(in_array(auth()->user()->role, ['pml','editor','admin']))
          <a class="btn-ghost" href="{{ route('pengolahan.index', ['rule' => $tr->code]) }}">Lihat</a>
          @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  @endif
</section>
@endif

<div class="grid2">
  <section class="panel">
    <h2>Status pencacahan</h2>
    <table class="tbl">
      <thead><tr><th>Status</th><th class="num">Jumlah</th><th>Proporsi</th></tr></thead>
      <tbody>
      @foreach ($statusLabels as $k => $lbl)
        @php($c = $byStatus[$k] ?? 0)
        <tr>
          <td><span class="chip s-{{ $k }}">{{ $lbl }}</span></td>
          <td class="num">{{ $c }}</td>
          <td><div class="bar"><i style="width: {{ $total ? round(100*$c/$total,1) : 0 }}%"></i></div></td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </section>

  <section class="panel">
    <h2>Progres per petugas</h2>
    <table class="tbl">
      <thead><tr><th>Petugas</th><th class="num">Sel/Tot</th><th>Progres</th></tr></thead>
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
  </section>
</div>

<section class="panel">
  <h2>Progres per desa <small class="muted">(diurutkan dari terendah)</small></h2>
  <table class="tbl">
    <thead><tr><th>Kecamatan</th><th>Desa</th><th class="num">Sel/Tot</th><th>Progres</th></tr></thead>
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
</section>
@endsection
