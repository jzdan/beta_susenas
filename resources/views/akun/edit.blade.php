@extends('layouts.app')
@section('title', 'Akun Saya · Sistem SUSENAS')
@section('content')
<x-back :href="route('monitoring.dashboard')" smart />
<h1>Akun Saya</h1>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<div class="grid2">
  <section class="panel">
    <h2>Keterangan</h2>
    <dl class="kv">
      <div><dt>Nama pengguna</dt><dd class="mono">{{ $u->username }}</dd></div>
      <div><dt>Nama</dt><dd>{{ $u->name }}</dd></div>
      <div><dt>Peran</dt><dd>{{ $u->roleLabel() }}</dd></div>
      @if($u->wilayah_scope)<div><dt>Cakupan wilayah</dt><dd class="mono">{{ $u->wilayah_scope }}</dd></div>@endif
    </dl>
    <p class="muted small">Perubahan nama/peran/cakupan dilakukan oleh admin.</p>
  </section>

  <section class="panel">
    <h2>Ganti kata sandi</h2>
    <form method="post" action="{{ route('akun.sandi') }}" class="stack-form">
      @csrf
      <label>Kata sandi saat ini
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <label>Kata sandi baru
        <input type="password" name="password" required autocomplete="new-password">
      </label>
      <label>Ulangi kata sandi baru
        <input type="password" name="password_confirmation" required autocomplete="new-password">
      </label>
      <p class="muted small">Minimal 8 karakter, memuat huruf dan angka, bukan sama dengan yang lama,
        dan tidak memuat nama pengguna.</p>
      <button type="submit">Simpan</button>
    </form>
  </section>
</div>
@endsection
