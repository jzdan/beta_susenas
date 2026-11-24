@extends('layouts.app')
@php($nav = 'jadwal')
@section('title', "Persiapan {$cycle->name} · Sistem SUSENAS")
@section('content')
<p><a class="btn-ghost" href="{{ route('admin.jadwal') }}">← Siklus &amp; jadwal</a></p>
<h1>Persiapan periode <small class="muted">{{ $cycle->name }}</small></h1>
<p class="muted">Status sekarang: <strong>{{ Istilah::cycleStatus($cycle->status) }}</strong>
  ({{ Istilah::CYCLE_STATUS_HELP[$cycle->status] ?? '' }}).</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <h2>Daftar periksa</h2>
  <ul class="prep-list">
    @foreach ($items as $i)
    <li class="prep-item {{ $i['ok'] ? 'is-ok' : ($i['wajib'] ? 'is-bad' : 'is-warn') }}">
      <span class="prep-mark" aria-hidden="true">{{ $i['ok'] ? '✓' : ($i['wajib'] ? '✕' : '!') }}</span>
      <div class="prep-body">
        <strong>{{ $i['label'] }}</strong>
        @if(!$i['wajib'])<span class="muted small">(disarankan)</span>@endif
        <div class="small muted">{{ $i['detail'] }}</div>
      </div>
      @if($i['url'] && !$i['ok'])<a class="btn-ghost" href="{{ $i['url'] }}">{{ $i['link'] }} →</a>@endif
    </li>
    @endforeach
  </ul>
  @if(count($versionErrors) > 1)
  <details class="mt">
    <summary style="cursor:pointer">Semua rujukan putus ({{ count($versionErrors) }})</summary>
    <ul>@foreach ($versionErrors as $e)<li class="small">{{ $e }}</li>@endforeach</ul>
  </details>
  @endif
</section>

<section class="panel">
  <h2>Buka periode lapangan</h2>
  @if($cycle->status === 'lapangan')
    <p>Periode lapangan sedang dibuka. Petugas bisa mengisi sampelnya.
      Untuk menutup, ubah status di <a href="{{ route('admin.jadwal') }}">Siklus &amp; jadwal</a>.</p>
  @elseif($cycle->isLocked())
    <p class="muted">Siklus sudah dikunci.</p>
  @elseif($siap)
    <p>Semua butir wajib beres. Setelah dibuka, petugas langsung melihat dan bisa mengisi sampelnya.</p>
    <form method="post" action="{{ route('admin.siklus.buka-lapangan', $cycle) }}"
      onsubmit="return confirm('Buka periode lapangan {{ $cycle->name }}?')">
      @csrf<button type="submit">Buka periode lapangan</button>
    </form>
  @else
    <p>Masih ada butir wajib (✕) yang belum beres. Bereskan dulu supaya petugas tidak mengisi kuesioner yang rusak
      atau sampel yang belum ditugaskan.</p>
    <form method="post" action="{{ route('admin.siklus.buka-lapangan', $cycle) }}"
      onsubmit="return confirm('Buka periode lapangan walau ada butir wajib yang belum beres? Tindakan ini tercatat di log audit.')">
      @csrf<input type="hidden" name="force" value="1">
      <button type="submit" class="btn-ghost" style="color:var(--err)">Tetap buka (tercatat di log audit)</button>
    </form>
  @endif
</section>
@endsection
