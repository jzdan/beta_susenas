@extends('layouts.app')
@section('title', 'Alur kerja · Sistem SUSENAS')
@section('content')
{{-- Inc 167 — urutan kerja admin dari awal sampai akhir (menu Bantuan → Alur kerja). Isi = panduan
     berkunci 'alur-kerja' (bisa diubah di Bantuan → Ubah panduan). Carousel langsung terbuka saat halaman dibuka. --}}
<h1>Alur kerja <x-panduan key="alur-kerja" label="▶ Lihat sebagai slide" :open-now="true" /></h1>
<p class="muted">Urutan menjalankan sistem dari awal sampai akhir. Klik langkah mana pun untuk membuka halamannya.</p>

@if($slides->isEmpty())
<section class="panel"><p class="muted">Belum ada isi. Tambahkan langkah di
  <a href="{{ route('admin.panduan.edit', 'alur-kerja') }}">Ubah panduan → Alur kerja admin</a>.</p></section>
@endif

@foreach ($slides->groupBy(fn ($s) => $s->section ?: 'Langkah') as $section => $items)
<section class="panel alur-bagian">
  <h2>{{ $section }}</h2>
  <ol class="alur-list">
    @foreach ($items as $s)
    @php($no = $slides->search(fn ($x) => $x->id === $s->id) + 1)
    <li>
      <span class="panduan-no">{{ $no }}</span>
      <div class="alur-teks">
        <b>{{ $s->title }}</b>
        @if($s->body)<span class="muted small">{{ $s->body }}</span>@endif
      </div>
      @if($s->link)<a class="btn-ghost" href="{{ $s->link }}">Buka →</a>@endif
    </li>
    @endforeach
  </ol>
</section>
@endforeach
@endsection
