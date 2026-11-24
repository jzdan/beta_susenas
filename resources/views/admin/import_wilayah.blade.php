@extends('layouts.app')
@php($nav = 'wil')
@section('title', 'Impor Wilkerstat · Sistem SUSENAS')
@section('content')
@php($byLevel = $byLevel ?? collect())
@php($fQ = $fQ ?? '')
@php($groups = $groups ?? collect())
<h1>Master Wilkerstat</h1>
<p class="muted"><em>Setup awal.</em> Muat daftar kecamatan &amp; desa kabupaten sekali di awal.
  Untuk perubahan kecil (pemekaran wilayah, ganti nama) pakai <strong>Tambah/Sunting/Hapus</strong> di bawah;
  untuk banyak sekaligus pakai impor CSV. Untuk daftar sampel tiap semester, pakai <strong>Impor Sampel</strong>.</p>
<p class="muted">Total kode: <strong>{{ $n }}</strong>
  @foreach (['kabupaten','kecamatan','desa'] as $lv)
    · {{ $byLevel[$lv] ?? 0 }} {{ $lv }}
  @endforeach
</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <h2>Tambah satu wilayah</h2>
  <p class="muted small">Untuk menambah beberapa kode sekaligus, pakai impor CSV di bawah. Kode yang sudah ada disunting lewat Telusuri.</p>
  <form method="post" action="{{ route('admin.wilayah.simpan') }}" class="row-form">
    @csrf
    <label class="small">Kode <input name="code" value="{{ old('code') }}" class="mono" style="width:120px" placeholder="mis. 3526190" required></label>
    <label class="small">Nama <input name="name" value="{{ old('name') }}" style="min-width:200px" required></label>
    <label class="small">Jenjang
      <select name="level" required>
        <option value="">—</option>
        @foreach (['kecamatan'=>7,'desa'=>10] as $lv => $len)
        <option value="{{ $lv }}" @selected(old('level')===$lv)>{{ $lv }} ({{ $len }} digit)</option>
        @endforeach
      </select>
    </label>
    <button type="submit">Tambah</button>
  </form>
  <p class="muted small">Induknya diambil otomatis dari awalan kode dan harus sudah ada.</p>
</section>

<section class="panel">
  <h2>Telusuri</h2>
  <form method="get" class="row-form">
    <input type="search" name="q" value="{{ $fQ }}" placeholder="cari kode (awalan) atau nama kecamatan/desa" style="min-width:280px" autofocus>
    <button type="submit">Cari</button>
    @if($fQ)<a class="btn-ghost" href="{{ route('admin.impor.wilayah') }}">Reset</a>@endif
  </form>

  @if($kabupaten)
    <p class="muted small mt">Kabupaten: <span class="mono">{{ $kabupaten->code }}</span> — {{ $kabupaten->name }}</p>
  @endif

  @if($groups->isEmpty())
    <p class="muted mt">{{ $fQ ? 'Tidak ada kecamatan/desa cocok.' : 'Belum ada kecamatan.' }}</p>
  @else
    @foreach ($groups as $g)
    <details class="rule-group" open>
      <summary style="cursor:pointer;font-weight:600">
        <span class="mono">{{ $g['kec']->code }}</span> — {{ $g['kec']->name }}
        <span class="muted small">— {{ $g['desa']->count() }} desa</span>
      </summary>
      <div class="tablewrap mt">
      <table class="tbl">
        <thead><tr><th>Kode</th><th>Nama</th><th>Jenjang</th><th></th></tr></thead>
        <tbody>
          <tr>
            <td class="mono">{{ $g['kec']->code }}</td>
            <td>
              <form method="post" action="{{ route('admin.wilayah.rename', $g['kec']->id) }}" class="row-form">
                @csrf
                <input name="name" value="{{ $g['kec']->name }}" style="min-width:200px">
                <button class="btn-ghost">Simpan</button>
              </form>
            </td>
            <td>kecamatan</td>
            <td class="right nowrap">
              <form method="post" action="{{ route('admin.wilayah.hapus', $g['kec']->id) }}" class="inline"
                onsubmit="return confirm('Hapus wilayah {{ $g['kec']->code }} — {{ $g['kec']->name }}?')">
                @csrf @method('DELETE')
                <button class="btn-ghost" type="submit">hapus</button>
              </form>
            </td>
          </tr>
          @foreach ($g['desa'] as $w)
          <tr>
            <td class="mono">{{ $w->code }}</td>
            <td>
              <form method="post" action="{{ route('admin.wilayah.rename', $w->id) }}" class="row-form">
                @csrf
                <input name="name" value="{{ $w->name }}" style="min-width:200px">
                <button class="btn-ghost">Simpan</button>
              </form>
            </td>
            <td>desa</td>
            <td class="right nowrap">
              <form method="post" action="{{ route('admin.wilayah.hapus', $w->id) }}" class="inline"
                onsubmit="return confirm('Hapus wilayah {{ $w->code }} — {{ $w->name }}?')">
                @csrf @method('DELETE')
                <button class="btn-ghost" type="submit">hapus</button>
              </form>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    </details>
    @endforeach
    <p class="muted small">Wilayah hanya bisa dihapus bila tidak punya anak &amp; tidak dipakai sampel. Untuk perubahan massal, pakai impor CSV di bawah.</p>
  @endif
</section>

<p class="muted">Impor CSV, kolom wajib: <span class="mono">code, name, level</span>
  (<span class="mono">level</span> = kabupaten / kecamatan / desa). Kode yang sudah ada akan <em>diperbarui</em>.</p>

@if($hasil)
<div class="alert {{ $hasil['dilewati'] && !($hasil['baru'] || $hasil['diperbarui']) ? 'err' : '' }}">
  @if($hasil['preview'] ?? false)<strong>Pratinjau</strong> (belum disimpan): @endif
  <strong>{{ $hasil['baru'] }}</strong> {{ ($hasil['preview'] ?? false) ? 'akan ditambah' : 'baru' }},
  <strong>{{ $hasil['diperbarui'] }}</strong> {{ ($hasil['preview'] ?? false) ? 'akan diperbarui' : 'diperbarui' }}.
  @if($hasil['dilewati'])<br>Dilewati {{ count($hasil['dilewati']) }}:
    <ul>@foreach ($hasil['dilewati'] as $d)<li class="small">{{ $d }}</li>@endforeach</ul>@endif
</div>
@endif

<section class="panel">
  <p class="muted small">Panjang kode: kabupaten 4, kecamatan 7, desa 10 digit. Baris diurutkan otomatis (induk dulu).</p>
  <form method="post" action="{{ route('admin.impor.wilayah.run') }}" class="stack-form">
    @csrf
    <label>Data CSV
      <textarea name="data" rows="12" class="mono" required
        placeholder="code,name,level&#10;3526,Kabupaten Bangkalan,kabupaten&#10;3526010,Kamal,kecamatan">{{ $hasil['data'] ?? '' }}</textarea>
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
<pre class="schema">code,name,level
3526,Kabupaten Bangkalan,kabupaten
3526010,Kamal,kecamatan
3526010001,Kamal,desa</pre>
</section>
@endsection
