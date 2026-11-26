@extends('layouts.app')
@section('title', 'Masuk · Sistem SUSENAS')
@section('content')
<div class="login-wrap">
  <div class="login-card">
    <div class="brand"><span class="dot"></span> Sistem SUSENAS</div>
    <p class="muted">Aplikasi internal · monitoring lapangan &amp; entri data</p>
    @if ($errors->any())
      <div class="alert err">{{ $errors->first() }}</div>
    @endif
    @if ($ssoAktif)
      <a class="btn-sso" href="{{ route('sso.bangdata') }}">Masuk dengan SSO Bangdata</a>
      <div class="pemisah"><span>atau masuk dengan akun lokal</span></div>
    @endif
    <form method="post" action="{{ route('login') }}">
      @csrf
      <input type="hidden" name="next" value="{{ $next }}">
      <label>Nama pengguna
        <input name="username" autocomplete="username" autofocus required value="{{ old('username') }}">
      </label>
      <label>Kata sandi
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit">Masuk</button>
    </form>
    <details class="demo">
      <summary>Akun demo</summary>
      <table>
        <tr><td>admin</td><td>admin123</td><td>Administrator</td></tr>
        <tr><td>pj_kamal</td><td>pj123</td><td>Penanggung Jawab Kecamatan Kamal</td></tr>
        <tr><td>rahmawati</td><td>pengawas123</td><td>Pengawas / Pengolah</td></tr>
        <tr><td>ppl_ani</td><td>operator123</td><td>Operator entri</td></tr>
        <tr><td>viewer</td><td>viewer123</td><td>Peninjau</td></tr>
      </table>
    </details>
  </div>
</div>
@endsection
