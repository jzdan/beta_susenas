@extends('layouts.app')
@php($nav = 'meta')
@section('title', "Bandingkan {$qv->code} · Sistem SUSENAS")
@section('content')
@if($qv->cycle)
@include('periode._hub_header', \App\Http\Controllers\PeriodeController::hubContext($qv->cycle) + ['hubTab' => 'kuesioner'])
@endif
<x-back :href="route('admin.metadata', ['qv' => $qv->id])" label="Kembali ke Kuesioner" />
<h1>Bandingkan versi <span class="mono">{{ $qv->code }}</span></h1>

<section class="panel">
  <form method="get" class="row-form">
    <label>Dibandingkan dengan
      <select name="dengan" onchange="this.form.submit()">
        @if(!$other)<option value="">— pilih versi —</option>@endif
        @foreach ($choices as $c)
          <option value="{{ $c->id }}" @selected($other && $other->id === $c->id)>
            {{ $c->code }}{{ $c->cycle ? ' · '.$c->cycle->label() : '' }}
          </option>
        @endforeach
      </select>
    </label>
    <noscript><button type="submit">Bandingkan</button></noscript>
  </form>
  @if(!$other)
    <p class="muted">Belum ada versi triwulan sebelumnya untuk survei ini. Pilih versi lain di atas.</p>
  @else
    <p class="muted small">Dari <strong class="mono">{{ $other->code }}</strong>{{ $other->cycle ? ' ('.$other->cycle->label().')' : '' }}
      ke <strong class="mono">{{ $qv->code }}</strong>{{ $qv->cycle ? ' ('.$qv->cycle->label().')' : '' }}.</p>
    <div class="diff-sum">
      <span class="chip diff-add">+{{ $totals['added'] }} ditambah</span>
      <span class="chip diff-del">−{{ $totals['removed'] }} dihapus</span>
      <span class="chip diff-chg">{{ $totals['changed'] }} diubah</span>
    </div>
    @if(!array_sum($totals))
      <p style="color:var(--ok)" class="mt">Kedua versi sama persis (blok, rincian, pilihan, baris tetap, aturan, parameter, agregat, turunan).</p>
    @endif
  @endif
</section>

@if($diff)
@foreach (\App\Services\VersionDiff::SECTIONS as $key => $label)
  @php($d = $diff[$key])
  @continue(!count($d['added']) && !count($d['removed']) && !count($d['changed']))
  <details class="panel" open>
    <summary style="cursor:pointer;font-weight:600">{{ $label }}
      <span class="muted small">(+{{ count($d['added']) }} · −{{ count($d['removed']) }} · {{ count($d['changed']) }} diubah)</span></summary>
    @if($d['added'])
      <h3 class="small mt">Ditambah</h3>
      <ul class="diff-list">@foreach ($d['added'] as $it)<li class="diff-add-li">+ {{ $it['name'] }}</li>@endforeach</ul>
    @endif
    @if($d['removed'])
      <h3 class="small mt">Dihapus</h3>
      <ul class="diff-list">@foreach ($d['removed'] as $it)<li class="diff-del-li">− {{ $it['name'] }}</li>@endforeach</ul>
    @endif
    @if($d['changed'])
      <h3 class="small mt">Diubah</h3>
      <div class="tablewrap">
      <table class="tbl">
        <thead><tr><th style="width:32%">Nama</th><th style="width:140px">Bagian</th><th>Sebelumnya → Sekarang</th></tr></thead>
        <tbody>
        @foreach ($d['changed'] as $it)
          @foreach ($it['fields'] as $f => [$old, $new])
          <tr>
            @if($loop->first)<td rowspan="{{ count($it['fields']) }}" class="small">{{ $it['name'] }}</td>@endif
            <td class="small">{{ \App\Services\VersionDiff::FIELD_LABELS[$f] ?? $f }}</td>
            <td class="small @if(in_array($f, ['expr', 'skip_expr', 'ref', 'value'], true)) mono @endif">
              <span class="diff-old">{{ $old === '' ? '(kosong)' : $old }}</span> → <span class="diff-new">{{ $new === '' ? '(kosong)' : $new }}</span>
            </td>
          </tr>
          @endforeach
        @endforeach
        </tbody>
      </table>
      </div>
    @endif
  </details>
@endforeach
@endif
@endsection
