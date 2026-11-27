@extends('layouts.app')
@section('title', "Panduan {$label} · Sistem SUSENAS")
@section('content')
<p class="page-actions"><x-back :href="route('admin.panduan')" label="Semua panduan" />
  @if($pageUrl)<a class="btn-ghost" href="{{ $pageUrl }}">Buka halamannya →</a>@endif</p>
<h1>Panduan: {{ $label }}</h1>
<p class="muted">Tiap langkah = satu slide: gambar (tangkapan layar) + judul + penjelasan singkat. Urutan di sini = urutan di carousel.
  Setelah diubah, panduan muncul sendiri sekali lagi untuk setiap pengguna.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

@forelse ($slides as $i => $s)
<section class="panel guide-edit {{ $s->active ? '' : 'is-off' }}" id="langkah-{{ $s->id }}">
  @if($s->section)<p class="panduan-section">{{ $s->section }}</p>@endif
  <h2><span class="panduan-no">{{ $i + 1 }}</span> {{ $s->title }} @unless($s->active)<span class="chip">disembunyikan</span>@endunless</h2>
  <div class="guide-edit-grid">
    <div class="guide-edit-img">
      @if($s->image_path)
        <img src="{{ route('panduan.gambar', $s) }}?v={{ $s->updated_at?->timestamp }}" alt="{{ $s->title }}">
      @else
        <div class="guide-noimg">Belum ada gambar</div>
      @endif
    </div>
    <form method="post" action="{{ route('admin.panduan.update', $s) }}" enctype="multipart/form-data" class="stack-form">
      @csrf
      <label>Bagian (opsional) <input name="section" value="{{ $s->section }}" maxlength="120" placeholder="mis. B. Tiap triwulan: menyiapkan"></label>
      <label>Judul <input name="title" value="{{ $s->title }}" maxlength="150" required></label>
      <label>Penjelasan <textarea name="body" rows="4" maxlength="2000">{{ $s->body }}</textarea></label>
      <label>Tautan "Buka halaman ini" (opsional) <input name="link" value="{{ $s->link }}" maxlength="255" placeholder="mis. /admin/impor-sampel"></label>
      <label>Ganti gambar (PNG/JPG, maks. 4 MB) <input type="file" name="image" accept="image/png,image/jpeg,image/webp"></label>
      @if($s->image_path)<label class="small"><input type="checkbox" name="remove_image" value="1"> Hapus gambar</label>@endif
      <label class="small"><input type="checkbox" name="active" value="1" @checked($s->active)> Tampilkan langkah ini</label>
      <div class="guide-edit-actions">
        <button type="submit">Simpan</button>
      </div>
    </form>
  </div>
  <div class="guide-edit-actions">
    <form method="post" action="{{ route('admin.panduan.move', $s) }}" class="inline">@csrf<input type="hidden" name="dir" value="up">
      <button type="submit" class="btn-ghost" @disabled($loop->first)>↑ Naik</button></form>
    <form method="post" action="{{ route('admin.panduan.move', $s) }}" class="inline">@csrf<input type="hidden" name="dir" value="down">
      <button type="submit" class="btn-ghost" @disabled($loop->last)>↓ Turun</button></form>
    <form method="post" action="{{ route('admin.panduan.destroy', $s) }}" class="inline"
      onsubmit="return confirm('Hapus langkah &quot;{{ addslashes($s->title) }}&quot;?')">@csrf
      <button type="submit" class="btn-ghost danger">Hapus</button></form>
  </div>
</section>
@empty
<section class="panel"><p class="muted">Halaman ini belum punya panduan. Tambahkan langkah pertama di bawah.</p></section>
@endforelse

<section class="panel">
  <h2>+ Tambah langkah</h2>
  <form method="post" action="{{ route('admin.panduan.store', $key) }}" enctype="multipart/form-data" class="stack-form">
    @csrf
    <label>Bagian (opsional) <input name="section" maxlength="120" placeholder="mis. B. Tiap triwulan: menyiapkan"></label>
    <label>Judul <input name="title" maxlength="150" required placeholder="mis. Pilih periode lalu unggah berkas"></label>
    <label>Tautan "Buka halaman ini" (opsional) <input name="link" maxlength="255" placeholder="mis. /admin/impor-sampel"></label>
    <label>Penjelasan <textarea name="body" rows="3" maxlength="2000" placeholder="1–3 kalimat singkat"></textarea></label>
    <label>Gambar (PNG/JPG, maks. 4 MB) <input type="file" name="image" accept="image/png,image/jpeg,image/webp"></label>
    <div class="guide-edit-actions"><button type="submit">Tambah langkah</button></div>
  </form>
</section>
@endsection
