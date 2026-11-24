@extends('layouts.app')
@php($nav = 'meta')
@section('title', "Sampel {$qv->code} · Sistem SUSENAS")
@section('content')
<p><a class="btn-ghost" href="{{ route('admin.metadata', ['qv' => $qv->id]) }}">← Metadata Kuesioner</a></p>
<h1>Rincian Sampel — {{ $qv->code }}</h1>
<p class="muted">Siklus <strong>{{ $cycle->name ?? '—' }}</strong>{{ $cycle?->period ? ' · periode '.$cycle->period : '' }}.
  Sampel diambil dari siklus versi ini; sampel yang sudah digantikan tidak dihitung.</p>

<div class="kpis">
  <div class="kpi"><span class="n">{{ $total }}</span><span class="l">Total sampel</span></div>
  <div class="kpi"><span class="n">{{ $total - $proses - $selesai }}</span><span class="l">Belum diisi</span></div>
  <div class="kpi"><span class="n">{{ $proses }}</span><span class="l">Sedang diisi</span></div>
  <div class="kpi"><span class="n">{{ $selesai }}</span><span class="l">Selesai dicacah</span></div>
  <div class="kpi"><span class="n">{{ count($kec) }}</span><span class="l">Kecamatan</span></div>
  <div class="kpi"><span class="n">{{ collect($kec)->sum(fn ($k) => count($k['desa'])) }}</span><span class="l">Desa</span></div>
</div>

@if(!$kec)
<section class="panel"><p class="muted">Belum ada sampel di siklus ini. Tambahkan lewat
  <a href="{{ route('admin.impor.sampel') }}">Impor Sampel</a>.</p></section>
@else

<section class="panel">
  <h2>Per kecamatan</h2>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>Kecamatan</th><th class="num">Desa</th><th class="num">Total</th><th class="num">Belum diisi</th>
      <th class="num">Sedang dikerjakan</th><th class="num">Selesai dicacah</th><th>Pencacah Lapangan</th><th>Pengawas Lapangan</th></tr></thead>
    <tbody>
    @foreach ($kec as $k)
      <tr>
        <td><a href="#kec-{{ $k['code'] }}">{{ $k['name'] }}</a> <span class="muted mono small">{{ $k['code'] }}</span></td>
        <td class="num">{{ count($k['desa']) }}</td>
        <td class="num">{{ $k['total'] }}</td>
        <td class="num">{{ $k['belum'] }}</td>
        <td class="num">{{ $k['proses'] }}</td>
        <td class="num">{{ $k['selesai'] }}</td>
        <td class="small">{{ $k['petugas'] ? implode(', ', $k['petugas']) : '—' }}</td>
        <td class="small">{{ $k['pml'] ? implode(', ', $k['pml']) : '—' }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <h2>Per petugas</h2>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>Petugas</th><th class="num">Sampel</th><th class="num">Sedang dikerjakan</th><th class="num">Selesai dicacah</th><th>Desa</th></tr></thead>
    <tbody>
    @foreach ($perPetugas as $p)
      <tr>
        <td>{{ $p['name'] }}</td>
        <td class="num">{{ $p['total'] }}</td>
        <td class="num">{{ $p['proses'] }}</td>
        <td class="num">{{ $p['selesai'] }}</td>
        <td class="small">{{ implode(', ', $p['desa']) }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
</section>

@foreach ($kec as $k)
<details class="panel" id="kec-{{ $k['code'] }}">
  <summary style="cursor:pointer;font-weight:600">
    Kecamatan {{ $k['name'] }}
    <span class="muted small">— {{ count($k['desa']) }} desa · {{ $k['selesai'] }} selesai dicacah ·
      {{ $k['proses'] }} sedang dikerjakan · {{ $k['belum'] }} belum diisi (dari {{ $k['total'] }})
      @if($k['diganti']) · {{ $k['diganti'] }} diganti @endif</span>
  </summary>
  <div class="tablewrap mt">
  <table class="tbl">
    <thead><tr><th>Blok Sensus</th><th>Nomor Rumah Tangga</th><th>Nama Kepala Rumah Tangga</th><th>Pencacah Lapangan</th><th>Pengawas Lapangan</th><th>Tahap</th><th></th></tr></thead>
    <tbody>
    @foreach ($k['desa'] as $d)
      <tr class="group-header"><th colspan="7">
        Desa {{ $d['name'] }} <span class="muted mono small">{{ $d['code'] }}</span>
        <span class="muted small">— {{ $d['selesai'] }} selesai dicacah · {{ $d['proses'] }} sedang dikerjakan ·
          {{ $d['belum'] }} belum diisi (dari {{ $d['total'] }}) ·
          petugas: {{ $d['petugas'] ? implode(', ', $d['petugas']) : 'belum ditugaskan' }}</span>
      </th></tr>
      @foreach ($d['rows'] as $s)
        @php($doc = $s->documents->first())
        <tr class="{{ $s->status === 'ganti_sampel' ? 'row-off' : '' }}">
          <td class="mono">{{ $s->no_bs }}</td>
          <td class="mono">{{ $s->no_ruta }}</td>
          <td>{{ $s->krt_name }}</td>
          <td>{{ $s->petugas->name ?? '—' }}</td>
          <td>{{ $s->pml->name ?? '—' }}</td>
          <td><span class="chip t-{{ Tahap::of($s, $doc) }}">{{ Tahap::label(Tahap::of($s, $doc)) }}</span></td>
          <td><a class="btn-ghost" href="{{ route('monitoring.sample', $s) }}">Buka</a></td>
        </tr>
      @endforeach
    @endforeach
    </tbody>
  </table>
  </div>
</details>
@endforeach
@endif
@endsection
