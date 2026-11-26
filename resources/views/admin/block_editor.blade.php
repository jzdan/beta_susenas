@extends('layouts.app')
@php($nav = 'meta')
@section('title', "Blok {$b->code} · Sistem SUSENAS")
@section('content')
<x-back :href="route('admin.metadata', ['qv' => $b->qversion_id])" label="Kembali ke Kuesioner" />
<h1>Blok {{ $b->code }} — {{ $b->title }}</h1>
@include('admin.partials._ro_version', ['roQv' => $b->qversion])
<p class="muted">Tipe:
  @if($b->repeat === 'art') roster (baris dinamis per anggota rumah tangga)
  @elseif($b->repeat === 'list') daftar (baris tetap, mis. komoditas)
  @elseif($b->repeat === 'list_art') daftar baris tetap × per anggota rumah tangga
  @else per rumah tangga @endif.
  @if($b->repeat !== 'ruta') Syarat lewati hanya berlaku untuk blok per rumah tangga. @endif</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <h2>Ubah blok</h2>
  <form method="post" action="{{ route('admin.blok.update', $b) }}" class="row-form">
    @csrf
    <label class="small">Kode <input name="code" value="{{ $b->code }}" required class="mono" style="width:90px"></label>
    <label class="small">Judul <input name="title" value="{{ $b->title }}" required style="min-width:280px"></label>
    <label class="small">Jenis
      <select name="repeat">
        @foreach (['ruta' => 'per rumah tangga', 'art' => 'roster per anggota rumah tangga', 'list' => 'daftar baris tetap', 'list_art' => 'daftar baris tetap × per anggota rumah tangga'] as $k => $lbl)
        <option value="{{ $k }}" @selected($b->repeat === $k)>{{ $lbl }}</option>
        @endforeach
      </select>
    </label>
    <label class="small" title="Daftar komoditas blok ini muncul di Aturan Validasi → Harga & Kalori">
      <input type="checkbox" name="harga_kalori" value="1" @checked($b->harga_kalori) @disabled($b->repeat !== 'list')>
      Blok harga &amp; kalori komoditas
    </label>
    <button type="submit">Simpan</button>
  </form>
  <p class="muted small">Mengganti <strong>kode</strong> ikut memperbarui aturan per-baris &amp; agregat yang menunjuk blok ini.
    Jenis blok hanya bisa diubah bila belum ada jawaban. Tanda <em>harga &amp; kalori</em> hanya untuk blok
    <em>daftar baris tetap</em>; daftar komoditasnya muncul di <strong>Aturan Validasi → Harga &amp; Kalori</strong>.</p>
</section>

@if(session('rowsResult'))
  @php($rr = session('rowsResult'))
  <div class="alert {{ $rr['dilewati'] && !$rr['dibuat'] ? 'err' : '' }}">
    <strong>{{ $rr['dibuat'] }}</strong> baris ditambahkan.
    @if($rr['dilewati'])<br>Dilewati {{ count($rr['dilewati']) }}:
      <ul>@foreach ($rr['dilewati'] as $x)<li class="small">{{ $x }}</li>@endforeach</ul>@endif
  </div>
@endif

@if($b->isRowBlock())
@php($cols = $refKeys->isNotEmpty() ? $refKeys : collect(['kalori','harga_min','harga_max']))
<section class="panel">
  <h2>Baris tetap ({{ $rowTotal }})</h2>
  <p class="muted small">Ubah angka di tabel lalu klik <em>Simpan</em> pada baris itu.
    Kolom referensi (mis. <span class="mono">kalori</span>, <span class="mono">harga_min</span>) dipakai aturan sebagai
    <span class="mono">ref_&lt;nama&gt;</span>.</p>

  <form method="get" class="row-form">
    <input type="search" name="q" value="{{ $fQ }}" placeholder="cari kode / nama komoditas" style="min-width:240px">
    <button type="submit">Cari</button>
    @if($fQ)<a class="btn-ghost" href="{{ route('admin.blok', $b) }}">Reset</a>@endif
    <span class="muted small">{{ $rows->count() }} ditampilkan</span>
  </form>

  @foreach ($rows as $r)
    <form method="post" action="{{ route('admin.baris.save', $b) }}" id="rs{{ $r->id }}" hidden>
      @csrf<input type="hidden" name="row_id" value="{{ $r->id }}">
    </form>
    <form method="post" action="{{ route('admin.baris.del', $r) }}" id="rd{{ $r->id }}" hidden
      onsubmit="return confirm('Hapus baris ini?')">@csrf</form>
  @endforeach

  <div class="tablewrap tall mt">
  <table class="tbl">
    <thead><tr><th style="width:52px">#</th><th style="width:76px">Kode</th><th>Label</th>
      @foreach ($cols as $c)<th style="width:86px">{{ $c }}</th>@endforeach<th></th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
      <tr>
        <td><input form="rs{{ $r->id }}" name="row_index" value="{{ $r->row_index }}" style="width:44px" class="mono"></td>
        <td><input form="rs{{ $r->id }}" name="code" value="{{ $r->code }}" style="width:66px" class="mono"></td>
        <td><input form="rs{{ $r->id }}" name="label" value="{{ $r->label }}" required style="width:100%;min-width:220px"></td>
        @foreach ($cols as $c)
        <td><input form="rs{{ $r->id }}" name="ref[{{ $c }}]" value="{{ $r->ref[$c] ?? '' }}" style="width:78px" class="mono" inputmode="decimal"></td>
        @endforeach
        <td class="nowrap">
          <button form="rs{{ $r->id }}" class="btn-ghost">Simpan</button>
          <button form="rd{{ $r->id }}" class="btn-ghost">Hapus</button>
        </td>
      </tr>
    @empty
      <tr><td colspan="{{ 4 + $cols->count() }}" class="muted">{{ $fQ ? 'Tidak ada yang cocok.' : 'Belum ada baris.' }}</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>

  <details class="mt">
    <summary style="cursor:pointer">Tambah baris baru</summary>
    <form method="post" action="{{ route('admin.baris.save', $b) }}" class="row-form mt">
      @csrf
      <input name="row_index" placeholder="no" style="width:56px" class="mono" value="{{ $rowTotal + 1 }}" required>
      <input name="code" placeholder="kode" style="width:80px" class="mono">
      <input name="label" placeholder="nama komoditas" required style="flex:1;min-width:200px">
      @foreach ($cols as $c)<input name="ref[{{ $c }}]" placeholder="{{ $c }}" style="width:90px" class="mono">@endforeach
      <button type="submit">Tambah</button>
    </form>
  </details>

  <details class="mt">
    <summary style="cursor:pointer">Impor / ganti banyak baris sekaligus (Excel / CSV)</summary>
    <form method="post" action="{{ route('admin.baris.import', $b) }}" class="stack-form mt" enctype="multipart/form-data">
      @csrf
      <p class="muted small">Kolom wajib <span class="mono">row_index, label</span>; opsional
        <span class="mono">code, kalori, harga_min, harga_max, satuan, max_kons</span> (kolom lain jadi data referensi baris).</p>
      <label>Unggah berkas <span class="mono">.xlsx</span> / <span class="mono">.csv</span>
        <input type="file" name="file" accept=".xlsx,.csv,.txt">
      </label>
      <label>Sheet (opsional, untuk .xlsx)
        <input type="text" name="sheet" placeholder="mis. KALORI">
      </label>
      <label>atau tempel data CSV
        <textarea name="data" rows="5" class="mono"
          placeholder="row_index,code,label,kalori,harga_min,harga_max&#10;1,002,Beras,3622,10000,25000"></textarea>
      </label>
      <div class="row-form">
        <label><input type="checkbox" name="mode" value="replace"> ganti semua baris</label>
        <button type="submit">Impor baris</button>
      </div>
    </form>
  </details>
</section>
@endif

<section class="panel">
  <h2>Rincian ({{ $questions->count() }})</h2>
  @include('admin.partials._tech_toggle')
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>#</th><th>Kode</th><th class="tech">Variabel</th><th>Label</th><th>Tipe</th><th class="tech">Lewati bila</th><th></th></tr></thead>
    <tbody>
    @forelse ($questions as $q)
      <tr>
        <td class="mono muted">{{ $loop->iteration }}</td>
        <td class="mono">{{ $q->code_rincian }}</td>
        <td class="mono tech">{{ $q->var_name }}</td>
        <td>{{ $q->label }}</td>
        <td>{{ Istilah::qtype($q->qtype) }}@if($q->unit) · {{ $q->unit }}@endif</td>
        <td class="mono muted small tech">{{ $q->skip_expr ?: '—' }}</td>
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
  </div>
</section>

<section class="panel">
  <h2>Tambah rincian</h2>
  <form method="post" action="{{ route('admin.rincian.add', $b) }}" class="stack-form">
    @csrf
    <div class="row-form">
      <input name="code_rincian" placeholder="Kode (mis. R234)" required>
      <input name="var_name" placeholder="Nama variabel (huruf, angka, garis bawah)" class="mono" required>
      <select name="qtype">@foreach ($qtypes as $t)<option value="{{ $t }}">{{ Istilah::qtype($t) }}</option>@endforeach</select>
      <input name="unit" placeholder="Satuan (mis. Rp / bulan)">
    </div>
    <input name="label" placeholder="Label / pertanyaan" required>
    <input name="skip_expr" placeholder="Lewati bila (opsional), mis. n(status_rumah) != 1" class="mono">
    @if($b->repeat !== 'ruta')<p class="muted small" style="margin:0">Di blok berulang, syarat lewati dihitung per baris (boleh memakai isian lain di baris yang sama). Sel yang dilewati dikunci dan diabaikan aturan &amp; hitungan.</p>@endif
    <input name="help_text" placeholder="Teks bantuan (opsional)">
    <button type="submit">Tambah rincian</button>
  </form>
  <p class="muted small">Untuk banyak rincian sekaligus, pakai <a href="{{ route('admin.impor') }}">Impor Rincian</a> (CSV).</p>
</section>
@endsection
