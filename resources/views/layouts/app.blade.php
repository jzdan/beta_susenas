<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Sistem SUSENAS')</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
  <link rel="stylesheet" href="{{ asset('css/app.css') }}">
  <script>
    // pra-render: pasang tema tersimpan agar tidak berkedip
    try {
      var t = localStorage.getItem('susenas-theme');
      if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t;
    } catch (e) {}
  </script>
</head>
<body>
@auth
  @php($u = auth()->user())
  <header class="topbar">
    <div class="brand"><span class="dot"></span> Sistem SUSENAS </div>
    <div class="whoami">
      @php($unread = $u->unreadNotifications()->count())
      <a href="{{ route('notifikasi.index') }}" class="notif-bell @if($unread) has-unread @endif" aria-label="Notifikasi">
        Notifikasi @if($unread)<span class="notif-count">{{ $unread > 9 ? '9+' : $unread }}</span>@endif
      </a>
      <button type="button" class="theme-toggle" data-theme-toggle aria-label="Ganti tema"><span data-theme-label>Tema</span></button>
      <a href="{{ route('akun') }}" style="text-decoration:none;color:inherit">{{ $u->name }}</a>
      <span class="role">{{ $u->roleLabel() }}@if($u->wilayah_scope) · {{ $u->wilayah_scope }}@endif</span>
      <a class="btn-ghost" href="{{ route('logout') }}">Keluar</a>
    </div>
  </header>
  <div class="shell">
    <nav class="side">
      <a href="{{ route('monitoring.dashboard') }}" class="{{ ($nav ?? '')=='dash' ? 'on' : '' }}">Dashboard</a>
      <a href="{{ route('monitoring.tasks') }}" class="{{ ($nav ?? '')=='tugas' ? 'on' : '' }}">Tugas Saya</a>
      <a href="{{ route('monitoring.peta') }}" class="{{ ($nav ?? '')=='peta' ? 'on' : '' }}">Peta Progres</a>
      <a href="{{ route('monitoring.samples') }}" class="{{ ($nav ?? '')=='sampel' ? 'on' : '' }}">Daftar Sampel</a>
      @if(in_array($u->role, ['pml','editor','admin']))
        <a href="{{ route('pengolahan.index') }}" class="{{ ($nav ?? '')=='pengolahan' ? 'on' : '' }}">Pengolahan</a>
      @endif
      @if(in_array($u->role, ['pml','editor','admin','viewer']))
        <a href="{{ route('tabulasi.index') }}" class="{{ ($nav ?? '')=='tabulasi' ? 'on' : '' }}">Tabulasi &amp; Ekspor</a>
        <a href="{{ route('tabulasi.indikator') }}" class="{{ ($nav ?? '')=='indikator' ? 'on' : '' }}">Indikator Kesejahteraan</a>
      @endif
      @if(in_array($u->role, ['pml','admin']))
        <a href="{{ route('monitoring.kinerja') }}" class="{{ ($nav ?? '')=='kinerja' ? 'on' : '' }}">Kinerja Tim</a>
        <a href="{{ route('monitoring.audit') }}" class="{{ ($nav ?? '')=='audit' ? 'on' : '' }}">Log Audit</a>
      @endif
      @if($u->role === 'admin')
        <div class="nav-sep">Admin · Kuesioner</div>
        <a href="{{ route('admin.metadata') }}" class="{{ ($nav ?? '')=='meta' ? 'on' : '' }}">Metadata</a>
        <a href="{{ route('admin.aturan') }}" class="{{ ($nav ?? '')=='aturan' ? 'on' : '' }}">Aturan Validasi</a>
        <a href="{{ route('admin.impor') }}" class="{{ ($nav ?? '')=='impor' ? 'on' : '' }}">Impor Rincian</a>
        <div class="nav-sep">Admin · Data</div>
        <a href="{{ route('admin.jadwal') }}" class="{{ ($nav ?? '')=='jadwal' ? 'on' : '' }}">Jadwal Lapangan</a>
        <a href="{{ route('admin.harga') }}" class="{{ ($nav ?? '')=='harga' ? 'on' : '' }}">Harga Referensi</a>
        <a href="{{ route('admin.impor.wilayah') }}" class="{{ ($nav ?? '')=='wil' ? 'on' : '' }}">Impor Wilkerstat</a>
        <a href="{{ route('admin.impor.sampel') }}" class="{{ ($nav ?? '')=='imsampel' ? 'on' : '' }}">Impor Sampel</a>
        <a href="{{ route('admin.impor.dokumen') }}" class="{{ ($nav ?? '')=='imdok' ? 'on' : '' }}">Impor Dokumen</a>
        <a href="{{ route('admin.pengguna') }}" class="{{ ($nav ?? '')=='pengguna' ? 'on' : '' }}">Pengguna</a>
      @endif
    </nav>
    <main class="content">
      @yield('content')
    </main>
  </div>
@else
  <button type="button" class="theme-toggle" data-theme-toggle aria-label="Ganti tema"
    style="position:fixed;top:14px;right:14px;z-index:20;background:var(--surface)"><span data-theme-label>Tema</span></button>
  @yield('content')
@endauth
<script src="{{ asset('js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
