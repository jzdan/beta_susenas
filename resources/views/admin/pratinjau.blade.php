@extends('layouts.app')
@php($nav = 'meta')
@section('title', 'Pratinjau Tampilan Entri · Sistem SUSENAS')
@section('content')
<p><a class="btn-ghost" href="{{ route('admin.metadata', ['qv' => $qv->id]) }}">← Metadata Kuesioner</a></p>
<h1>Pratinjau Tampilan Entri</h1>
<div class="alert" style="background:var(--accbg);border:1px solid var(--acc)">
  <strong>Pratinjau tampilan Pencacah Lapangan.</strong> Tampilannya dibuat dengan cara yang sama
  dengan halaman entri, jadi persis seperti yang dilihat petugas, tanpa menyimpan data apa pun
  (isian kosong; baris/baris komoditas tetap tampil utuh).
  <br><span class="muted small">Gunakan untuk mengecek tata letak &amp; keterbacaan kuesioner sebelum rilis semester.</span>
</div>

@include('admin.partials._qv_switch')

<div class="entry-layout">
  @include('entri._form')
  @include('entri._ringkasan')
</div>
@endsection
