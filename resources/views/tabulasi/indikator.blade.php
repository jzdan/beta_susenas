@extends('layouts.app')
@php($nav = 'indikator')
@section('title', 'Indikator Kesejahteraan · Sistem SUSENAS')
@section('content')
<h1>Indikator Kesejahteraan</h1>
<p class="muted">Rekap <strong>internal untuk QC/pemantauan — BUKAN estimasi resmi</strong>.
  Angka tertimbang memakai <em>design weight</em> yang belum dikalibrasi.
  {{ $total }} dokumen dalam cakupan Anda.</p>

<form method="get" class="row-form">
  <input type="hidden" name="selesai" value="1">
  <label>Pengeluaran per kapita
    <select name="var">
      @foreach ($numVars as $v)<option value="{{ $v }}" @selected($v === $var)>{{ $v }}</option>@endforeach
    </select>
  </label>
  <label>Garis kemiskinan (Rp)
    <input name="gk" value="{{ (int) $gk }}" style="width:110px">
  </label>
  <button type="submit">Hitung</button>
</form>

@if(empty($result))
  <p class="muted">Belum ada dokumen dengan variabel <span class="mono">{{ $var }}</span>.</p>
@else

@if($hist)
<div class="viz-root panel" style="overflow-x:auto">
  <h2>Sebaran pengeluaran per kapita per bulan <small class="muted">— semua wilayah, n={{ $result[0]['n'] }}</small></h2>
  <svg viewBox="0 0 {{ $hist['vw'] }} {{ $hist['vh'] }}" width="100%" style="max-width:760px" role="img"
       aria-label="Histogram pengeluaran per kapita; garis kemiskinan Rp {{ number_format($gk, 0, ',', '.') }}">
    <line x1="{{ $hist['ml'] }}" y1="{{ $hist['baseline_y'] }}" x2="{{ $hist['baseline_x2'] }}" y2="{{ $hist['baseline_y'] }}"
          stroke="var(--viz-axis)" stroke-width="1"/>
    @foreach ($hist['bars'] as $b)
      @if($b['h'] > 0)
      <rect x="{{ $b['x'] }}" y="{{ $b['y'] }}" width="{{ $b['w'] }}" height="{{ $b['h'] }}" rx="3" fill="var(--viz-bar)">
        <title>{{ $b['range'] }}: {{ $b['count'] }} rumah tangga</title>
      </rect>
      <text x="{{ $b['cx'] }}" y="{{ $b['y'] - 4 }}" text-anchor="middle" font-size="11">{{ $b['count'] }}</text>
      @endif
    @endforeach
    <line x1="{{ $hist['gk_x'] }}" y1="{{ $hist['gk_y1'] }}" x2="{{ $hist['gk_x'] }}" y2="{{ $hist['gk_y2'] }}"
          stroke="var(--viz-gk)" stroke-width="2" stroke-dasharray="4 3"/>
    <text x="{{ $hist['gk_x'] + 4 }}" y="{{ $hist['gk_text_y'] }}" fill="var(--viz-gk)" font-size="11" font-weight="600">{{ $hist['gk_label'] }}</text>
    @foreach ($hist['ticks'] as $t)
    <text x="{{ $t['x'] }}" y="{{ $hist['tick_y'] }}" text-anchor="middle" font-size="11">{{ $t['label'] }}</text>
    @endforeach
    @if($hist['over'] > 0)
    <text x="{{ $hist['over_x'] }}" y="{{ $hist['over_y'] }}" text-anchor="end" font-size="11">+{{ $hist['over'] }} di atas skala</text>
    @endif
  </svg>
  <p class="muted small">Tiap batang = jumlah rumah tangga pada rentang pengeluaran per kapita. Garis merah = garis kemiskinan.</p>
</div>
@endif

<div class="roster-scroll">
<table class="tbl">
  <thead><tr>
    <th>Wilayah</th><th class="num">n</th>
    <th class="num">P0 (tak tertimbang)</th><th class="num">P0 (tertimbang)</th>
    <th class="num">Rata-rata</th><th class="num">Median</th>
    <th class="num">Porsi makanan</th><th class="num">Estimasi populasi</th>
    <th>Sebaran kelas ({{ implode(' / ', $classLabels) }})</th>
  </tr></thead>
  <tbody>
  @foreach ($result as $r)
    <tr @class(['row-has-warn' => $loop->first])>
      <td>{{ $r['label'] }}</td>
      <td class="num">{{ $r['n'] }}</td>
      <td class="num">{{ is_null($r['p0_u']) ? '—' : $r['p0_u'].'%' }}</td>
      <td class="num">{{ is_null($r['p0_w']) ? '—' : $r['p0_w'].'%' }}</td>
      <td class="num">{{ is_null($r['mean']) ? '—' : number_format($r['mean'], 0, ',', '.') }}</td>
      <td class="num">{{ is_null($r['median']) ? '—' : number_format($r['median'], 0, ',', '.') }}</td>
      <td class="num">{{ is_null($r['food_share']) ? '—' : $r['food_share'].'%' }}</td>
      <td class="num">{{ is_null($r['pop_est']) ? '—' : number_format($r['pop_est'], 0, ',', '.') }}</td>
      <td class="mono small">{{ implode(' / ', $r['dist']) }}</td>
    </tr>
  @endforeach
  </tbody>
</table>
</div>
<p class="muted small">P0 = <em>headcount ratio</em>: persentase rumah tangga dengan pengeluaran per kapita
  di bawah garis kemiskinan (Rp {{ number_format($gk, 0, ',', '.') }}).
  Baris pertama = seluruh cakupan.</p>
@endif

<p class="mt"><a class="btn-ghost" href="{{ route('tabulasi.index') }}">← Tabulasi umum</a></p>
@endsection
