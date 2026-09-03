@extends('layouts.app')
@php($nav = 'imsampel')
@section('title', 'Impor Daftar Sampel · Sistem SUSENAS')
@section('content')
<h1>Impor Daftar Sampel Rumah Tangga</h1>
<p class="muted">Kolom wajib: <span class="mono">wilayah_code, no_bs, no_ruta</span>.
  Opsional: <span class="mono">krt_name, petugas, pml</span> (isi dengan <em>username</em>).
  Sampel duplikat dan kode desa yang tidak ada di master wilayah akan dilewati.</p>

@if($hasil)
<div class="alert {{ $hasil['dilewati'] && !$hasil['dibuat'] ? 'err' : '' }}">
  <strong>{{ $hasil['dibuat'] }}</strong> sampel ditambahkan ke siklus {{ $hasil['cycle']->name }}.
  @if($hasil['dilewati'])<br>Dilewati {{ count($hasil['dilewati']) }}:
    <ul>@foreach ($hasil['dilewati'] as $d)<li class="small">{{ $d }}</li>@endforeach</ul>@endif
</div>
@endif

<section class="panel">
  <form method="post" action="{{ route('admin.impor.sampel.run') }}" class="stack-form">
    @csrf
    <label>Siklus survei
      <select name="cycle_id" required>
        @foreach ($cycles as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->period }})</option>@endforeach
      </select>
    </label>
    <label>Data CSV
      <textarea name="data" rows="12" class="mono" required
        placeholder="wilayah_code,no_bs,no_ruta,krt_name,petugas,pml&#10;3526101001,001B,001,Sugianto,ppl_ani,rahmawati"></textarea>
    </label>
    <button type="submit">Impor</button>
  </form>
</section>

<section class="panel">
  <h2>Contoh</h2>
<pre class="schema">wilayah_code,no_bs,no_ruta,krt_name,petugas,pml
3526101001,001B,001,Sugianto,ppl_ani,rahmawati
3526101001,001B,002,Hj. Maryam,ppl_ani,rahmawati</pre>
</section>
@endsection
