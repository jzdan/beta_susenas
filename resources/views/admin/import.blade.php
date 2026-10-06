@extends('layouts.app')
@php($nav = 'impor')
@section('title', 'Impor Rincian · Sistem SUSENAS')
@section('content')
<h1>Impor Rincian dari CSV</h1>
<p class="muted">Tempel daftar rincian (mis. seluruh komoditas Blok IV.1) sekali jalan.
  Baris pertama = header. Kolom wajib: <span class="mono">kode_rincian, var_name, label</span>.
  Kolom opsional: <span class="mono">qtype, unit, skip_expr, help_text</span> (qtype default <span class="mono">decimal</span>).</p>

@include('admin.partials._qv_switch')

@if($hasil)
<div class="alert {{ $hasil['dilewati'] && !$hasil['dibuat'] ? 'err' : '' }}">
  <strong>{{ $hasil['dibuat'] }}</strong> rincian ditambahkan ke Blok {{ $hasil['blok']->code }}.
  @if($hasil['dilewati'])<br>Dilewati {{ count($hasil['dilewati']) }}:
    <ul>@foreach ($hasil['dilewati'] as $d)<li class="small">{{ $d }}</li>@endforeach</ul>@endif
</div>
@endif

<section class="panel">
  <form method="post" action="{{ route('admin.impor.run') }}" class="stack-form">
    @csrf
    <label>Blok tujuan
      <select name="block_id" required>
        @foreach ($blocks as $b)<option value="{{ $b->id }}">{{ $b->code }} — {{ $b->title }}</option>@endforeach
      </select>
    </label>
    <label>Data CSV
      <textarea name="data" rows="14" class="mono" required
        placeholder="kode_rincian,var_name,label,qtype,unit&#10;R2,iv1_beras,Beras,decimal,Kg"></textarea>
    </label>
    <button type="submit">Impor</button>
  </form>
</section>

<section class="panel">
  <h2>Contoh</h2>
<pre class="schema">kode_rincian,var_name,label,qtype,unit
R2,iv1_beras,"Beras (lokal, medium, premium, impor)",decimal,Kg
R3,iv1_beras_ketan,Beras ketan,decimal,Kg
R4,iv1_jagung_basah,Jagung basah dengan kulit,decimal,Kg
R5,iv1_jagung_pipilan,Jagung pipilan/beras jagung,decimal,Ons</pre>
</section>
@endsection
