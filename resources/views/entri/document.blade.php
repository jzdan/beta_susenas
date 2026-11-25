@extends('layouts.app')
@php($nav = 'sampel')
@section('title', "Entri sampel #{$doc->id} · Sistem SUSENAS")
@section('content')
<p><x-back :href="route('monitoring.sample', $sample)" smart />
  <a class="btn-ghost" href="{{ route('entri.print', $doc) }}">Cetak / arsip</a></p>
<h1>Entri Kuesioner <small class="muted">{{ $sample->cycle->name ?? '' }}</small></h1>
<p class="muted mono">
  {{ $qv->code }} · {{ $wil[$sample->wilayah_code] ?? $sample->wilayah_code }} ·
  Blok Sensus {{ $sample->no_bs }} · Rumah Tangga {{ $sample->no_ruta }} · isian #{{ $doc->id }}
  <span class="chip t-{{ Tahap::ofDocument($doc) }}">{{ Tahap::label(Tahap::ofDocument($doc)) }}</span>
</p>

@if ($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

@if ($petugasLocked ?? false)
<div class="alert">Periode lapangan sampel ini sudah ditutup. Isian hanya bisa dilihat, tidak bisa diubah.</div>
@endif

@if ($doc->status === 'dikembalikan')
<div class="alert err">
  <strong>Sampel dikembalikan untuk perbaikan</strong>
  @if($doc->returned_at)<span class="muted small">· {{ $doc->returned_at->format('d M Y H:i') }}</span>@endif
  <br>{{ $doc->return_reason }}
  <br><span class="muted small">Perbaiki isian yang dimaksud, lalu tekan "Kirim ulang" di panel ringkasan.</span>
</div>
@endif

<div class="entry-layout">
  @include('entri._form')
  @include('entri._ringkasan')
</div>
@endsection
