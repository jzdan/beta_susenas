@extends('layouts.app')
@php($nav = 'meta')
@section('title', 'Impor Versi Kuesioner · Sistem SUSENAS')
@section('content')
<p><a class="btn-ghost" href="{{ route('admin.metadata') }}">← Metadata</a></p>
<h1>Impor Paket Versi Kuesioner</h1>
<p class="muted">Tempel isi paket JSON (dari <span class="mono">Ekspor JSON</span> di instansi lain)
  atau unggah berkasnya. Paket diperiksa dulu sebelum diimpor sebagai versi baru.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif

@if($hasil)
<div class="alert {{ $hasil['errors'] ? 'err' : '' }}">
  @if($hasil['errors'])
    <strong>{{ count($hasil['errors']) }} galat</strong> — paket tidak bisa diimpor:
    <ul>@foreach ($hasil['errors'] as $e)<li class="small">{{ $e }}</li>@endforeach</ul>
  @elseif($hasil['ok'] ?? false)
    <strong>Paket lolos pemeriksaan.</strong> {{ ($hasil['warnings'] ?? []) ? 'Ada peringatan di bawah — centang "tetap impor" bila memang disengaja.' : 'Klik "Impor sekarang".' }}
  @endif
  @if($hasil['warnings'] ?? [])
    <br><strong>Peringatan:</strong>
    <ul>@foreach ($hasil['warnings'] as $w)<li class="small">{{ $w }}</li>@endforeach</ul>
  @endif
</div>
@endif

<section class="panel">
  <form method="post" action="{{ route('admin.versi.import.run') }}" class="stack-form" enctype="multipart/form-data">
    @csrf
    <label>Tempel JSON
      <textarea name="data" rows="10" class="mono" placeholder='{ "format": "susenas.qversion/1", ... }'>{{ $data ?? '' }}</textarea>
    </label>
    <label>…atau unggah berkas <input type="file" name="file" accept=".json,application/json"></label>
    <div class="row-form">
      <label class="small">Siklus tujuan
        <select name="cycle_id" required>
          @foreach ($cycles as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
      </label>
      <label class="small">Kode versi (opsional, timpa dari paket)
        <input name="code" placeholder="mis. VSEN27.KP">
      </label>
    </div>
    <label class="small"><input type="checkbox" name="force" value="1"> Tetap impor walau ada peringatan</label>
    <div class="row-form">
      <button type="submit" name="mode" value="check">Periksa</button>
      <button type="submit" name="mode" value="commit" class="btn-ghost"
        onclick="return confirm('Impor paket ini sebagai versi kuesioner baru?')">Impor sekarang</button>
    </div>
  </form>
</section>
@endsection
