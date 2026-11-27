@extends('layouts.app')
@php($nav = 'meta')
@section('title', "Buku Kode {$qv->code} · Sistem SUSENAS")
@section('content')
<p class="no-print"><x-back :href="route('admin.metadata', ['qv' => $qv->id])" label="Kembali ke Kuesioner" />
  <button class="btn-ghost" onclick="window.print()">Cetak</button>
  <a class="btn-ghost" href="{{ route('admin.versi.export', $qv) }}">Ekspor JSON</a></p>

<div class="print-doc">
<h1>Buku Kode — {{ $qv->code }}</h1>
<p class="muted">Periode: {{ $cycle->name ?? '—' }} · berlaku {{ $qv->effective_date }} ·
  {{ $qv->blocks->count() }} blok ·
  {{ $qv->blocks->sum(fn ($b) => $b->questions->count()) }} rincian ·
  {{ $qv->rules->count() }} aturan · {{ $qv->derived->count() }} nilai turunan ·
  {{ $qv->aggregates->count() }} agregat</p>

@foreach ($qv->blocks->sortBy('order') as $b)
<section class="print-block">
  <h2>Blok {{ $b->code }} — {{ $b->title }}
    <span class="muted small">({{ Istilah::repeat($b->repeat) }})</span></h2>
  <div class="tablewrap">
  <table class="tbl mini">
    <thead><tr><th>Rincian</th><th>Variabel</th><th>Label</th><th>Tipe</th><th>Satuan</th><th>Kode nilai</th><th>Skip bila</th></tr></thead>
    <tbody>
    @foreach ($b->questions->sortBy('order') as $q)
      <tr>
        <td class="mono">{{ $q->code_rincian }}</td>
        <td class="mono">{{ $q->var_name }}</td>
        <td>{{ $q->label }}</td>
        <td>{{ Istilah::qtype($q->qtype) }}</td>
        <td>{{ $q->unit }}</td>
        <td class="small">{{ $q->options->map(fn ($o) => "{$o->code}={$o->label}")->implode(' · ') }}</td>
        <td class="mono small">{{ $q->skip_expr }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  @if($b->repeat === 'list' && $b->rows->count())
  <details class="small">
    <summary>{{ $b->rows->count() }} baris tetap</summary>
    <div class="tablewrap">
    <table class="tbl mini">
      <thead><tr><th>#</th><th>Kode</th><th>Label</th><th>Ref</th></tr></thead>
      <tbody>
      @foreach ($b->rows->sortBy('order') as $r)
        <tr><td>{{ $r->row_index }}</td><td class="mono">{{ $r->code }}</td><td>{{ $r->label }}</td>
          <td class="small mono">{{ $r->ref ? json_encode($r->ref, JSON_UNESCAPED_UNICODE) : '' }}</td></tr>
      @endforeach
      </tbody>
    </table>
    </div>
  </details>
  @endif
</section>
@endforeach

@if($qv->parameters->count())
<section class="print-block">
  <h2>Parameter</h2>
  <div class="tablewrap">
  <table class="tbl mini"><thead><tr><th>Nama</th><th>Nilai</th><th>Catatan</th></tr></thead><tbody>
    @foreach ($qv->parameters->sortBy('name') as $p)
    <tr><td class="mono">{{ $p->name }}</td><td>{{ $p->value }}</td><td class="small">{{ $p->note }}</td></tr>
    @endforeach
  </tbody></table>
  </div>
</section>
@endif

@if($qv->derived->count())
<section class="print-block">
  <h2>Nilai turunan</h2>
  <div class="tablewrap">
  <table class="tbl mini"><thead><tr><th>var_name</th><th>Label</th><th>Ekspresi</th></tr></thead><tbody>
    @foreach ($qv->derived->sortBy('order') as $d)
    <tr><td class="mono">{{ $d->var_name }}</td><td>{{ $d->label }}</td><td class="mono small">{{ $d->expr }}</td></tr>
    @endforeach
  </tbody></table>
  </div>
</section>
@endif

@if($qv->aggregates->count())
<section class="print-block">
  <h2>Agregat</h2>
  <div class="tablewrap">
  <table class="tbl mini"><thead><tr><th>Nama</th><th>Label</th><th>Blok</th><th>Operasi</th><th>Var nilai</th></tr></thead><tbody>
    @foreach ($qv->aggregates->sortBy('order') as $a)
    <tr><td class="mono">{{ $a->name }}</td><td>{{ $a->label }}</td><td class="mono">{{ $a->block_code }}</td>
      <td>{{ $a->op }}</td><td class="mono small">{{ $a->value_var }}</td></tr>
    @endforeach
  </tbody></table>
  </div>
</section>
@endif

@if($qv->rules->count())
<section class="print-block">
  <h2>Aturan validasi</h2>
  <div class="tablewrap">
  <table class="tbl mini"><thead><tr><th>Kode</th><th>Jenis</th><th>Tingkat</th><th>Per baris blok</th><th>Ekspresi (benar = masalah)</th><th>Pesan</th><th>Aktif</th></tr></thead><tbody>
    @foreach ($qv->rules->sortBy('code') as $r)
    <tr>
      <td class="mono">{{ $r->code }}</td><td>{{ Istilah::scope($r->scope) }}</td>
      <td>{{ Istilah::severity($r->severity) }}</td><td class="mono">{{ $r->row_block }}</td>
      <td class="mono small">{{ $r->expr }}</td><td class="small">{{ $r->message }}</td>
      <td>{{ $r->active ? '✔' : '—' }}</td>
    </tr>
    @endforeach
  </tbody></table>
  </div>
</section>
@endif
</div>
@endsection
