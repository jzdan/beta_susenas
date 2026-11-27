<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Sistem SUSENAS')</title>
  {{-- Inc 162: aplikasi terpasang (PWA) — Android (manifest) & iPhone (apple-*) --}}
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#172032">
  <link rel="icon" type="image/png" sizes="32x32" href="/icons/icon-32.png">
  <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="SUSENAS">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  @auth<meta name="app-user" content="{{ auth()->id() }}">@endauth
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;450;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
  <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
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
        <span class="lbl">Notifikasi</span> @if($unread)<span class="notif-count">{{ $unread > 9 ? '9+' : $unread }}</span>@endif
      </a>
      <button type="button" class="theme-toggle" data-theme-toggle aria-label="Ganti tema"><span data-theme-label>Tema</span></button>
      <a href="{{ route('akun') }}" class="avatar-row" style="text-decoration:none;color:inherit">
        <x-avatar :user="$u" :size="28" />
        <span class="nm">{{ $u->name }}</span>
      </a>
      <span class="role">{{ $u->roleLabel() }}@if($u->wilayah_scope) · {{ $u->wilayah_scope }}@endif</span>
      <a class="btn-ghost" href="{{ route('logout') }}">Keluar</a>
    </div>
  </header>
  <div class="shell">
    @php($navKey = in_array($nav ?? '', ['periode', 'meta', 'impor', 'aturan', 'jadwal', 'imsampel', 'imdok'], true) ? 'periode' : ($nav ?? ''))
    <nav class="side">
      {{-- layar HP/tablet: menu dilipat di balik tombol ini (sebelumnya satu strip geser yang sangat panjang) --}}
      <button type="button" class="nav-toggle" aria-expanded="false" aria-label="Buka menu">
        <span>☰ Menu</span><span class="cur"></span>
      </button>
      @if(in_array($u->role, ['pml','pj','admin']))
        @if($runningQuarter ?? null)
        <a class="now" href="{{ route('periode.triwulan', [$runningQuarter['year'], $runningQuarter['q']]) }}">
          <small>Triwulan berjalan</small>
          <b>TW{{ $runningQuarter['q'] }} {{ $runningQuarter['year'] }} · {{ $runningQuarter['status'] ? Istilah::cycleStatus($runningQuarter['status']) : 'belum disiapkan' }}</b>
          @foreach ($runningQuarter['notes'] ?? [] as $note)<span class="now-note">⚠ {{ $note }}</span>@endforeach
        </a>
        @if(!empty($otherField))
        <div class="now-also">Juga sedang pencacahan:
          @foreach ($otherField as $g)
            <a href="{{ route('periode.triwulan', [$g['year'], $g['q']]) }}">TW{{ $g['q'] }} {{ $g['year'] }}</a>@if($g['note']) <span class="{{ $g['warn'] ? 'now-warn' : '' }}">({{ $g['note'] }})</span>@endif{{ $loop->last ? '' : ' ·' }}
          @endforeach
        </div>
        @endif
        @endif
        <a href="{{ route('periode.index') }}" class="{{ $navKey=='periode' ? 'on' : '' }}">Periode Survei</a>
      @endif
      <a href="{{ route('monitoring.dashboard') }}" class="{{ ($nav ?? '')=='dash' ? 'on' : '' }}">Dashboard</a>
      <a href="{{ route('monitoring.tasks') }}" class="{{ ($nav ?? '')=='tugas' ? 'on' : '' }}">Tugas Saya</a>
      <a href="{{ route('monitoring.peta') }}" class="{{ ($nav ?? '')=='peta' ? 'on' : '' }}">Peta Progres</a>
      <a href="{{ route('monitoring.samples') }}" class="{{ ($nav ?? '')=='sampel' ? 'on' : '' }}">Daftar Sampel</a>
      @if(in_array($u->role, ['pml','pj','admin']))
        <a href="{{ route('pengolahan.index') }}" class="{{ ($nav ?? '')=='pengolahan' ? 'on' : '' }}">Pengolahan</a>
      @endif
      @if(in_array($u->role, ['pml','pj','admin','viewer']))
        <a href="{{ route('tabulasi.index') }}" class="{{ ($nav ?? '')=='tabulasi' ? 'on' : '' }}">Tabulasi &amp; Ekspor</a>
        <a href="{{ route('tabulasi.indikator') }}" class="{{ ($nav ?? '')=='indikator' ? 'on' : '' }}">Indikator Kesejahteraan</a>
      @endif
      @if(in_array($u->role, ['pml','pj','admin']))
        <a href="{{ route('monitoring.kinerja') }}" class="{{ ($nav ?? '')=='kinerja' ? 'on' : '' }}">Kinerja Tim</a>
        <a href="{{ route('monitoring.audit') }}" class="{{ ($nav ?? '')=='audit' ? 'on' : '' }}">Log Audit</a>
      @endif
      @if($u->role === 'admin')
        {{-- Inc 154: Metadata, Impor Rincian, Aturan Validasi, Jadwal, Impor Sampel & Impor Isian
             pindah ke dalam hub Periode Survei (tab per siklus). Yang tersisa: data lintas siklus. --}}
        <div class="nav-sep">Data bersama</div>
        <a href="{{ route('admin.impor.wilayah') }}" class="{{ ($nav ?? '')=='wil' ? 'on' : '' }}">Wilayah</a>
        <a href="{{ route('admin.pengguna') }}" class="{{ ($nav ?? '')=='pengguna' ? 'on' : '' }}">Pengguna</a>
        <div class="nav-sep">Bantuan</div>
        <a href="{{ route('panduan.alur') }}" class="{{ ($nav ?? '')=='alur' ? 'on' : '' }}">Alur kerja</a>
        <a href="{{ route('admin.panduan') }}" class="{{ ($nav ?? '')=='panduan' ? 'on' : '' }}">Ubah panduan</a>
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
@stack('panduan')
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
@stack('scripts')
</body>
</html>
