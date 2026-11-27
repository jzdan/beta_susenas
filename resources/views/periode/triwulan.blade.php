@extends('layouts.app')
@php($nav = 'periode')
@section('title', "TW{$tw} {$tahun} · Sistem SUSENAS")
@section('content')
<a class="back-link" href="{{ route('periode.tahun', $tahun) }}">← Kembali ke {{ $tahun }}</a>
<div class="crumb"><a href="{{ route('periode.index') }}">Periode</a> › <a href="{{ route('periode.tahun', $tahun) }}">{{ $tahun }}</a> › TW{{ $tw }}</div>
<h1>TW{{ $tw }} {{ $tahun }} <small class="muted">{{ \App\Models\SurveyCycle::MONTHS[$tw * 3 - 2] }}–{{ \App\Models\SurveyCycle::MONTHS[$tw * 3] }}</small></h1>

@if(session('ok'))<div class="alert ok">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

@if($cycles->isNotEmpty())
<p class="muted">Survei yang berjalan di triwulan ini. Klik salah satu untuk mengelolanya.</p>
<div class="pgrid">
  @foreach ($cycles as $c)
    @php($s = $stats[$c->id])
    @php($v = \App\Services\CyclePrep::versionFor($c))
    <a class="pcard" href="{{ route('periode.hub', $c) }}">
      <div class="pcard-head ph-{{ $c->status }}">
        <h3>{{ $c->label() }}</h3>
        <p>{{ $c->monthName() }} {{ $tahun }}@if($v) · <span class="mono">{{ $v->code }}</span>@endif</p>
        <span class="pbadge">{{ Istilah::cycleStatus($c->status) }}</span>
      </div>
      <div class="pcard-body">
        @if($s['total'])
          <div class="prow"><span>Sampel terisi</span><b>{{ $s['terisi'] }} / {{ $s['total'] }}</b></div>
          <div class="bar"><i style="width: {{ round(100 * $s['terisi'] / $s['total']) }}%"></i></div>
          <div class="prow mt"><span>Menunggu pemeriksaan</span><b>{{ $s['diperiksa'] }}</b></div>
          @if($s['kesalahan'])<div class="prow"><span>Masih ada kesalahan</span><b style="color:var(--err)">{{ $s['kesalahan'] }}</b></div>@endif
        @else
          <p class="small muted">Belum ada sampel.@if(auth()->user()->role === 'admin') Muat lewat tab Sampel.@endif</p>
        @endif
      </div>
      <div class="pcard-foot"><span>@if($c->field_start && $c->field_end)Pencacahan {{ $c->field_start->format('d M') }}–{{ $c->field_end->format('d M') }}@else Jadwal belum diisi @endif</span><span>Buka →</span></div>
    </a>
  @endforeach
</div>

@if($beban->isNotEmpty())
<section class="panel">
  <h2>Beban petugas triwulan ini <small class="muted">gabungan semua survei</small></h2>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>Pencacah Lapangan</th>
      @foreach ($surveys as $sv)<th class="num">{{ $sv }}</th>@endforeach
      <th class="num">Total</th><th>Selesai dicacah</th></tr></thead>
    <tbody>
    @foreach ($beban as $b)
      <tr><td>{{ $b['name'] }}</td>
        @foreach ($surveys as $sv)<td class="num">{{ $b['per'][$sv] ?? 0 }}</td>@endforeach
        <td class="num"><strong>{{ $b['total'] }}</strong></td>
        <td style="min-width:150px"><div class="bar"><i style="width: {{ $b['total'] ? round(100 * $b['selesai'] / $b['total']) : 0 }}%"></i></div>
          <span class="small muted">{{ $b['selesai'] }} / {{ $b['total'] }}</span></td></tr>
    @endforeach
    </tbody>
  </table>
  </div>
</section>
@endif
@endif

@if(auth()->user()->role === 'admin' && $candidates->isNotEmpty())
<section class="panel" id="siapkan">
  <h2>{{ $cycles->isEmpty() ? 'Siapkan triwulan ini' : 'Tambah survei ke triwulan ini' }}</h2>
  <p class="muted small">Kuesioner, aturan validasi, neraca, turunan, dan patokan harga disalin dari periode terakhir
    survei yang dipilih. Periode baru berstatus <em>Persiapan</em>: petugas belum melihatnya sampai pencacahan dibuka.</p>
  <form method="post" action="{{ route('periode.siapkan', [$tahun, $tw]) }}" class="stack-form">
    @csrf
    <div class="pick-list">
      @foreach ($candidates as $cand)
        <div class="pick">
          <input type="checkbox" name="pick[]" value="{{ $cand['key'] }}" id="pk{{ $loop->index }}" @checked($cand['checked'])>
          <span>
            <label for="pk{{ $loop->index }}"><strong>{{ $cand['label'] }}</strong></label>
            <label class="small muted">salin dari
              <select name="src[{{ $cand['key'] }}]">
                @foreach ($cand['options'] as $o)
                  <option value="{{ $o['version']->id }}" @selected($o['version']->id === $cand['default'])>{{ $o['version']->code }} · {{ $o['version']->cycle->monthName() }} {{ $o['version']->cycle->year() }} · {{ $o['blocks'] }} blok{{ $o['rows'] ? ' · '.$o['rows'].' baris' : '' }} · {{ $o['rules'] }} aturan{{ $o['blocks'] ? '' : ' (kosong)' }}</option>
                @endforeach
              </select>
            </label>
          </span>
        </div>
      @endforeach
    </div>
    <div class="rule-grid">
      <label>Bulan periode
        <select name="month">
          @foreach (range($tw * 3 - 2, $tw * 3) as $m)
            <option value="{{ $m }}" @selected($m === $tw * 3)>{{ \App\Models\SurveyCycle::MONTHS[$m] }} {{ $tahun }}</option>
          @endforeach
        </select>
      </label>
      <label>Mulai lapangan (opsional) <input type="date" name="field_start"></label>
      <label>Selesai lapangan (opsional) <input type="date" name="field_end"></label>
    </div>
    <div><button type="submit">Siapkan</button></div>
  </form>
</section>
@elseif($cycles->isEmpty())
<section class="panel"><p class="muted">Triwulan ini belum disiapkan.</p></section>
@endif
@endsection
