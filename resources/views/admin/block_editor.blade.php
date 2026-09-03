@extends('layouts.app')
@php($nav = 'meta')
@section('title', "Blok {$b->code} · Sistem SUSENAS")
@section('content')
<p><a class="btn-ghost" href="{{ route('admin.metadata') }}">← Metadata</a></p>
<h1>Blok {{ $b->code }} — {{ $b->title }}</h1>
<p class="muted">Tipe:
  @if($b->repeat === 'art') roster (baris dinamis per ART)
  @elseif($b->repeat === 'list') daftar (baris tetap, mis. komoditas)
  @else per rumah tangga @endif.
  @if($b->repeat !== 'ruta')Skip pattern tidak berlaku di blok berulang pada MVP ini.@endif</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <h2>Ubah blok</h2>
  <form method="post" action="{{ route('admin.blok.update', $b) }}" class="row-form">
    @csrf
    <input name="title" value="{{ $b->title }}" required style="min-width:280px">
    <select name="repeat">
      @foreach (['ruta' => 'per rumah tangga', 'art' => 'roster per ART', 'list' => 'daftar baris tetap'] as $k => $lbl)
      <option value="{{ $k }}" @selected($b->repeat === $k)>{{ $lbl }}</option>
      @endforeach
    </select>
    <button type="submit">Simpan</button>
    <span class="muted small">Tipe berulang hanya bisa diubah bila blok belum punya jawaban.</span>
  </form>
</section>

@if(session('rowsResult'))
  @php($rr = session('rowsResult'))
  <div class="alert {{ $rr['dilewati'] && !$rr['dibuat'] ? 'err' : '' }}">
    <strong>{{ $rr['dibuat'] }}</strong> baris ditambahkan.
    @if($rr['dilewati'])<br>Dilewati {{ count($rr['dilewati']) }}:
      <ul>@foreach ($rr['dilewati'] as $x)<li class="small">{{ $x }}</li>@endforeach</ul>@endif
  </div>
@endif

@if($b->repeat === 'list')
<section class="panel">
  <h2>Baris tetap ({{ $rows->count() }})</h2>
  <table class="tbl">
    <thead><tr><th>#</th><th>Kode</th><th>Label</th><th>Referensi (kalori / harga)</th></tr></thead>
    <tbody>
    @forelse ($rows->sortBy('row_index') as $r)
      <tr><td class="mono muted">{{ $r->row_index }}</td><td class="mono">{{ $r->code }}</td>
        <td>{{ $r->label }}</td>
        <td class="mono small muted">{{ $r->ref ? json_encode($r->ref, JSON_UNESCAPED_SLASHES) : '—' }}</td></tr>
    @empty
      <tr><td colspan="4" class="muted">Belum ada baris.</td></tr>
    @endforelse
    </tbody>
  </table>
  <form method="post" action="{{ route('admin.baris.import', $b) }}" class="stack-form mt">
    @csrf
    <label>Impor baris CSV — kolom wajib <span class="mono">row_index, label</span>; opsional
      <span class="mono">code, kalori, harga_min, harga_max</span>
      <textarea name="data" rows="8" class="mono" required
        placeholder="row_index,code,label,kalori,harga_min,harga_max&#10;1,01,Beras,3600,12000,16000&#10;2,02,Telur ayam ras,1600,24000,30000"></textarea>
    </label>
    <div class="row-form">
      <label><input type="checkbox" name="mode" value="replace"> ganti semua baris</label>
      <button type="submit">Impor baris</button>
    </div>
  </form>
</section>
@endif

<section class="panel">
  <h2>Rincian ({{ $questions->count() }})</h2>
  <table class="tbl">
    <thead><tr><th>#</th><th>Kode</th><th>Variabel</th><th>Label</th><th>Tipe</th><th>Skip bila</th><th></th></tr></thead>
    <tbody>
    @forelse ($questions as $q)
      <tr>
        <td class="mono muted">{{ $loop->iteration }}</td>
        <td class="mono">{{ $q->code_rincian }}</td>
        <td class="mono">{{ $q->var_name }}</td>
        <td>{{ $q->label }}</td>
        <td>{{ $q->qtype }}@if($q->unit) · {{ $q->unit }}@endif</td>
        <td class="mono muted small">{{ $q->skip_expr ?: '—' }}</td>
        <td class="right nowrap">
          <form method="post" action="{{ route('admin.rincian.order', $q) }}">@csrf<input type="hidden" name="arah" value="naik"><button class="btn-ghost" @disabled($loop->first)>↑</button></form>
          <form method="post" action="{{ route('admin.rincian.order', $q) }}">@csrf<input type="hidden" name="arah" value="turun"><button class="btn-ghost" @disabled($loop->last)>↓</button></form>
          <a class="btn-ghost" href="{{ route('admin.rincian', $q) }}">Sunting</a>
        </td>
      </tr>
    @empty
      <tr><td colspan="7" class="muted">Belum ada rincian.</td></tr>
    @endforelse
    </tbody>
  </table>
</section>

<section class="panel">
  <h2>Tambah rincian</h2>
  <form method="post" action="{{ route('admin.rincian.add', $b) }}" class="stack-form">
    @csrf
    <div class="row-form">
      <input name="code_rincian" placeholder="Kode (mis. R234)" required>
      <input name="var_name" placeholder="var_name (huruf/underscore)" class="mono" required>
      <select name="qtype">@foreach ($qtypes as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select>
      <input name="unit" placeholder="Satuan (mis. Rp / bulan)">
    </div>
    <input name="label" placeholder="Label / pertanyaan" required>
    <input name="skip_expr" placeholder="Skip bila (ekspresi, opsional) — mis. n(status_rumah) != 1" class="mono">
    <input name="help_text" placeholder="Teks bantuan (opsional)">
    <button type="submit">Tambah rincian</button>
  </form>
  <p class="muted small">Untuk banyak rincian sekaligus, pakai <a href="{{ route('admin.impor') }}">Impor Rincian</a> (CSV).</p>
</section>
@endsection
