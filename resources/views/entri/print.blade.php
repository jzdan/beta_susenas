@extends('layouts.app')
@section('title', "Cetak sampel #{$doc->id} · Sistem SUSENAS")
@section('content')
<div class="print-doc">
<div class="no-print" style="margin-bottom:12px">
  <a class="btn-ghost" href="{{ route('entri.form', $doc) }}">← Kembali</a>
  <button type="button" class="btn-ghost" onclick="window.print()">Cetak / simpan PDF</button>
</div>

<h1>Salinan Kuesioner SUSENAS</h1>
<table class="tbl mini" style="max-width:640px">
  <tr><td>Versi kuesioner</td><td class="mono">{{ $qv->code }}</td></tr>
  <tr><td>Wilayah</td><td>{{ $wil[$sample->wilayah_code] ?? $sample->wilayah_code }}
    <span class="mono muted">{{ $sample->wilayah_code }}</span></td></tr>
  <tr><td>Blok Sensus / Rumah Tangga</td><td class="mono">{{ $sample->no_bs }} / {{ $sample->no_ruta }}</td></tr>
  <tr><td>Kepala rumah tangga</td><td>{{ $sample->krt_name }}</td></tr>
  <tr><td>Sampel</td><td>#{{ $doc->id }} · status <strong>{{ $doc->status }}</strong>
    @if($doc->cleaned_at) · dibersihkan {{ $doc->cleaned_at->format('d-m-Y') }}@endif</td></tr>
</table>

@foreach ($blocks as $b)
<section class="print-block">
  <h2>Blok {{ $b['code'] }} — {{ $b['title'] }}</h2>
  @if(empty($b['rows']))
    <p class="muted small">(tidak ada isian)</p>
  @elseif($b['repeat'] === 'ruta')
    <table class="tbl mini">
      @foreach ($b['rows'][0]['cells'] as $c)
        @if($c['raw'] !== '' || $c['clean'] !== null)
        <tr>
          <td style="width:55%"><span class="mono muted">{{ $c['code'] }}</span> {{ $c['label'] }}</td>
          <td>@include('entri._printval', ['c' => $c])</td>
        </tr>
        @endif
      @endforeach
    </table>
  @else
    <div class="tablewrap">
    <table class="tbl mini">
      <thead><tr><th>{{ in_array($b['repeat'], ['list','list_art']) ? 'Rincian' : 'Anggota rumah tangga' }}</th>
        @foreach ($b['columns'] as $col)<th><span class="mono muted">{{ $col['code'] }}</span> {{ $col['label'] }}</th>@endforeach
      </tr></thead>
      <tbody>
      @foreach ($b['rows'] as $row)
        <tr>
          <td class="mono">{{ $row['label'] ?? $row['idx'] }}</td>
          @foreach ($row['cells'] as $c)<td>@include('entri._printval', ['c' => $c])</td>@endforeach
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  @endif
</section>
@endforeach

@if(count($derived))
<section class="print-block">
  <h2>Nilai turunan</h2>
  <table class="tbl mini">
    @foreach ($derived as $d)
    <tr><td>{{ $d['label'] }}</td><td class="num">{{ is_null($d['value']) ? '—' :
      (is_numeric($d['value']) ? number_format((float) $d['value'], 0, ',', '.') : $d['value']) }}</td></tr>
    @endforeach
  </table>
</section>
@endif

<section class="print-block">
  <h2>Temuan validasi ({{ count($warn) }})</h2>
  @forelse ($warn as $w)
    <p class="small"><span class="mono">{{ $w['code'] }}</span>
      <span class="flag {{ $w['status'] }}">{{ $w['status'] }}</span>
      {{ $w['message'] }}
      @if($w['row'] > 0)<span class="muted">(baris {{ $w['row'] }})</span>@endif</p>
  @empty
    <p class="muted small">Tidak ada.</p>
  @endforelse
</section>

@if(count($corrections))
<section class="print-block">
  <h2>Jejak koreksi cleaning ({{ count($corrections) }})</h2>
  <div class="tablewrap">
  <table class="tbl mini">
    <thead><tr><th>Variabel</th><th>Baris</th><th>Semula</th><th>Menjadi</th><th>Alasan</th><th>Oleh / waktu</th></tr></thead>
    <tbody>
    @foreach ($corrections as $c)
      <tr>
        <td class="mono">{{ $c['var'] }}</td>
        <td>{{ $c['row'] ?: '—' }}</td>
        <td class="mono">{{ $c['old'] === '' ? '(kosong)' : $c['old'] }}</td>
        <td class="mono">{{ $c['new'] === '' ? '(kosong)' : $c['new'] }}</td>
        <td class="small">{{ $c['reason'] }}</td>
        <td class="small muted">{{ $c['by'] }} · {{ $c['at'] }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
</section>
@endif

@if(count($notes))
<section class="print-block">
  <h2>Catatan isian ({{ count($notes) }})</h2>
  @foreach ($notes as $n)
    <p class="small"><strong>{{ $n['by'] }}</strong> <span class="muted">{{ $n['at'] }}</span><br>{{ $n['body'] }}</p>
  @endforeach
</section>
@endif

<p class="muted small print-foot">Dicetak {{ now()->format('d-m-Y H:i') }} oleh {{ auth()->user()->name }}.
  Rekap internal untuk arsip/pemeriksaan mutu — bukan laporan resmi.</p>
</div>
@endsection
