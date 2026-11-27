@extends('layouts.app')
@section('title', 'Panduan · Sistem SUSENAS')
@section('content')
<h1>Panduan bergambar</h1>
<p class="muted">Panduan langkah demi langkah yang muncul di halaman admin (tombol <b>? Panduan</b>). Pilih halaman untuk mengubah isinya.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif

<section class="panel">
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>Halaman</th><th class="num">Langkah</th><th class="num">Tampil</th><th></th></tr></thead>
    <tbody>
    @foreach ($pages as $key => $p)
      @php($c = $counts[$key] ?? null)
      <tr>
        <td><a href="{{ route('admin.panduan.edit', $key) }}"><strong>{{ $p['label'] }}</strong></a></td>
        <td class="num">{{ $c->n ?? 0 }}</td>
        <td class="num">{{ $c->aktif ?? 0 }}</td>
        <td class="right"><a class="btn-ghost" href="{{ route('admin.panduan.edit', $key) }}">{{ $c ? 'Ubah' : 'Buat' }}</a></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
</section>
@endsection
