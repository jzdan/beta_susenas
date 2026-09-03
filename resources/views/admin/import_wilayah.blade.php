@extends('layouts.app')
@php($nav = 'wil')
@section('title', 'Impor Wilkerstat · Sistem SUSENAS')
@section('content')
@php($byLevel = $byLevel ?? collect())
@php($fQ = $fQ ?? '')
@php($list = $list ?? collect())
<h1>Master Wilkerstat</h1>
<p class="muted">Total kode: <strong>{{ $n }}</strong>
  @foreach (['provinsi','kabupaten','kecamatan','desa'] as $lv)
    · {{ $byLevel[$lv] ?? 0 }} {{ $lv }}
  @endforeach
</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif

<section class="panel">
  <h2>Telusuri</h2>
  <form method="get" class="row-form">
    <input type="search" name="q" value="{{ $fQ }}" placeholder="kode (awalan) atau nama" style="min-width:240px" autofocus>
    <button type="submit">Cari</button>
    @if($fQ)<a class="btn-ghost" href="{{ route('admin.impor.wilayah') }}">Reset</a>@endif
  </form>
  @if($fQ)
    @if($list->isEmpty())
      <p class="muted mt">Tidak ada wilayah cocok.</p>
    @else
    <div class="tablewrap mt">
    <table class="tbl">
      <thead><tr><th>Kode</th><th>Nama</th><th>Jenjang</th><th>Induk</th><th></th></tr></thead>
      <tbody>
      @foreach ($list as $w)
        <tr>
          <td class="mono">{{ $w->code }}</td>
          <td>
            <form method="post" action="{{ route('admin.wilayah.rename', $w->id) }}" class="row-form">
              @csrf
              <input name="name" value="{{ $w->name }}" style="min-width:200px">
              <button class="btn-ghost">Simpan</button>
            </form>
          </td>
          <td>{{ $w->level }}</td>
          <td class="mono muted">{{ $w->parent_code ?: '—' }}</td>
          <td class="right"><a class="btn-ghost" href="{{ route('admin.impor.wilayah', ['q' => $w->code]) }}">anak →</a></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
    <p class="muted small">Maks 300 baris. Untuk perubahan massal, pakai impor CSV di bawah.</p>
    @endif
  @endif
</section>

<p class="muted">Impor CSV — kolom wajib: <span class="mono">code, name, level</span>. Opsional: <span class="mono">parent_code</span>.
  <span class="mono">level</span> = provinsi / kabupaten / kecamatan / desa. Kode yang sudah ada akan <em>diperbarui</em>.</p>

@if($hasil)
<div class="alert {{ $hasil['dilewati'] && !($hasil['baru'] || $hasil['diperbarui']) ? 'err' : '' }}">
  @if($hasil['preview'] ?? false)<strong>PRATINJAU</strong> (belum disimpan) — @endif
  <strong>{{ $hasil['baru'] }}</strong> {{ ($hasil['preview'] ?? false) ? 'akan ditambah' : 'baru' }},
  <strong>{{ $hasil['diperbarui'] }}</strong> {{ ($hasil['preview'] ?? false) ? 'akan diperbarui' : 'diperbarui' }}.
  @if($hasil['dilewati'])<br>Dilewati {{ count($hasil['dilewati']) }}:
    <ul>@foreach ($hasil['dilewati'] as $d)<li class="small">{{ $d }}</li>@endforeach</ul>@endif
</div>
@endif

<section class="panel">
  <p class="muted small">Validasi: panjang kode sesuai jenjang (prov 2, kab 4, kec 6, desa 10),
    <span class="mono">parent_code</span> harus awalan dari <span class="mono">code</span> dan sudah ada.
    Baris diurutkan otomatis (induk dulu).</p>
  <form method="post" action="{{ route('admin.impor.wilayah.run') }}" class="stack-form">
    @csrf
    <label>Data CSV
      <textarea name="data" rows="12" class="mono" required
        placeholder="code,name,level,parent_code&#10;3526,Kabupaten Bangkalan,kabupaten,35">{{ $hasil['data'] ?? '' }}</textarea>
    </label>
    <div class="row-form">
      <button type="submit" name="mode" value="preview">Pratinjau</button>
      <button type="submit" name="mode" value="commit" class="btn-ghost"
        onclick="return confirm('Simpan impor wilayah ini?')">Impor &amp; simpan</button>
    </div>
  </form>
</section>

<section class="panel">
  <h2>Contoh</h2>
<pre class="schema">code,name,level,parent_code
35,Jawa Timur,provinsi,
3526,Kabupaten Bangkalan,kabupaten,35
352610,Kamal,kecamatan,3526
3526101001,Kamal,desa,352610</pre>
</section>
@endsection
