@extends('layouts.app')
@php($nav = 'imdok')
@section('title', 'Impor Isian Massal · Sistem SUSENAS')
@section('content')
<x-back :href="request('cycle') ? route('monitoring.samples', ['cycle' => request('cycle')]) : route('periode.index')" smart />
<h1>Impor Isian Massal (format lebar)</h1>
<p class="muted">Satu baris CSV = satu rumah tangga. Kolom kunci wajib:
  <span class="mono">wilayah_code, no_bs, no_ruta</span> (opsional <span class="mono">krt_name</span>).
  Kolom sisanya = <strong>nama variabel</strong>. Untuk blok berulang pakai
  <span class="mono">var__1</span>, <span class="mono">var__2</span> (angka = nomor anggota rumah tangga / baris).
  Cocok untuk memuat ekspor CAPI ke sistem ini.</p>

@if($hasil)
<div class="alert {{ $hasil['dilewati'] && !($hasil['dibuat'] || $hasil['diperbarui']) ? 'err' : '' }}">
  @if($hasil['preview'])
    <strong>PRATINJAU</strong> (belum disimpan) — {{ $hasil['cycle'] }}.
    Dari {{ $hasil['nRows'] }} baris: <strong>{{ $hasil['dibuat'] }}</strong> akan jadi isian baru,
    <strong>{{ $hasil['diperbarui'] }}</strong> akan diperbarui.
  @else
    <strong>{{ $hasil['dibuat'] }}</strong> isian baru, <strong>{{ $hasil['diperbarui'] }}</strong> diperbarui.
  @endif
  Hasil validasi: {{ $hasil['totErr'] }} kesalahan, {{ $hasil['totWarn'] }} peringatan.
  @if($hasil['dilewati'])<br>Dilewati {{ count($hasil['dilewati']) }}:
    <ul>@foreach ($hasil['dilewati'] as $x)<li class="small">{{ $x }}</li>@endforeach</ul>@endif
</div>

<div class="grid2">
  <section class="panel">
    <h2>Kolom dikenal &amp; terpakai ({{ count($hasil['used']) }})</h2>
    <p class="mono small muted">{{ implode(', ', $hasil['used']) ?: '—' }}</p>
  </section>
  <section class="panel">
    <h2>Kolom TIDAK dikenal ({{ count($hasil['unknown']) }})</h2>
    @if($hasil['unknown'])
      <p class="muted small">Kolom ini diabaikan. Buat <strong>alias</strong> di bawah bila ini kolom CAPI
        yang bernama beda, atau periksa akhiran <span class="mono">__n</span>.</p>
      <ul>@foreach ($hasil['unknown'] as $col => $cnt)<li class="small"><span class="mono">{{ $col }}</span> — {{ $cnt }} baris</li>@endforeach</ul>
    @else
      <p class="muted small">Semua kolom non-kunci dikenali. ✓</p>
    @endif
    @if(!empty($hasil['alias_used']))
      <p class="small">Diterjemahkan lewat alias: <span class="mono">{{ implode(', ', array_keys($hasil['alias_used'])) }}</span></p>
    @endif
  </section>
</div>
@endif

<section class="panel">
  <form method="post" action="{{ route('admin.impor.dokumen.run') }}" class="stack-form">
    @csrf
    <label>Siklus survei
      <select name="cycle_id" required>
        @foreach (\App\Models\SurveyCycle::orderByDesc('id')->get() as $c)
        <option value="{{ $c->id }}" @selected(($hasil['cycle_id'] ?? (int) request('cycle')) === $c->id)>{{ $c->name }} ({{ $c->period }})</option>
        @endforeach
      </select>
    </label>
    <label>Data CSV
      <textarea name="data" rows="12" class="mono" required
        placeholder="wilayah_code,no_bs,no_ruta,krt_name,r301,r407__1,r407__2&#10;3526010001,001B,005,Kasan,3,45,40">{{ $hasil['data'] ?? '' }}</textarea>
    </label>
    <div class="row-form">
      <button type="submit" name="mode" value="preview">Pratinjau</button>
      <button type="submit" name="mode" value="commit" class="btn-ghost"
        onclick="return confirm('Simpan impor ini ke database?')">Impor &amp; simpan</button>
    </div>
    <p class="muted small">Pratinjau menjalankan seluruh proses (termasuk validasi) lalu <strong>membatalkan</strong> —
      tidak ada yang tersimpan. Jalankan pratinjau dulu untuk memastikan pemetaan kolom benar.</p>
  </form>
</section>

<section class="panel">
  <h2>Template &amp; kamus data</h2>
  <p class="muted small">Unduh header CSV yang tepat + kamus variabel untuk tim CAPI/pengolah.
    <span class="mono">art</span> = jumlah baris anggota rumah tangga maksimum pada template.</p>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>Siklus</th><th>Template kosong</th><th>Kamus data</th><th>Ekspor jawaban</th></tr></thead>
    <tbody>
    @foreach (\App\Models\SurveyCycle::orderByDesc('id')->get() as $c)
      <tr>
        <td>{{ $c->name }} <span class="mono muted">{{ $c->period }}</span></td>
        <td>
          <a class="btn-ghost" href="{{ route('admin.impor.dokumen.template', [$c, 'art' => 8]) }}">art=8</a>
          <a class="btn-ghost" href="{{ route('admin.impor.dokumen.template', [$c, 'art' => 15]) }}">art=15</a>
        </td>
        <td><a class="btn-ghost" href="{{ route('admin.impor.dokumen.kamus', $c) }}">CSV kamus</a></td>
        <td>
          <a class="btn-ghost" href="{{ route('admin.impor.dokumen.export', $c) }}">nilai mentah</a>
          <a class="btn-ghost" href="{{ route('admin.impor.dokumen.export', [$c, 'bersih' => 1]) }}">nilai bersih</a>
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  <p class="muted small">Ekspor jawaban memakai format kolom yang sama dengan impor —
    bisa diunduh, disunting di luar sistem, lalu dimuat ulang.</p>
</section>

<section class="panel">
  <h2>Alias kolom (pemetaan nama kolom CAPI)</h2>
  <p class="muted small">Bila berkas CAPI memakai nama kolom berbeda dari sistem, daftarkan pemetaannya di sini.
    Untuk kolom anggota rumah tangga, petakan per nomor anggota: <span class="mono">KRT_UMUR_1 → r407__1</span>.</p>

  @foreach ($aliases as $code => $list)
    <p class="small"><strong>{{ $code }}</strong>:
      @foreach ($list as $a)
        <span class="chip">{{ $a->source_col }} → {{ $a->target_col }}
          <form method="post" action="{{ route('admin.impor.dokumen.alias.del', $a) }}" style="display:inline">
            @csrf<button class="btn-ghost" style="padding:0 4px;font-size:.7rem">×</button>
          </form></span>
      @endforeach
    </p>
  @endforeach

  <form method="post" action="{{ route('admin.impor.dokumen.alias.save') }}" class="stack-form mt">
    @csrf
    <label>Siklus
      <select name="cycle_id" required>
        @foreach (\App\Models\SurveyCycle::orderByDesc('id')->get() as $c)
        <option value="{{ $c->id }}">{{ $c->name }}</option>
        @endforeach
      </select>
    </label>
    <div class="row-form">
      <input name="source_col" placeholder="kolom di berkas CAPI" class="mono">
      <span>→</span>
      <input name="target_col" placeholder="kolom sistem (var / var__n)" class="mono">
    </div>
    <label>atau tempel banyak (satu per baris: <span class="mono">sumber,target</span>)
      <textarea name="bulk" rows="4" class="mono" placeholder="KRT_UMUR_1,r407__1&#10;KRT_UMUR_2,r407__2"></textarea>
    </label>
    <button type="submit">Simpan alias</button>
  </form>
</section>

<section class="panel">
  <h2>Variabel yang dikenal per blok</h2>
  @foreach ($blocks as $b)
  <p class="small"><strong>{{ $b->code }}</strong> ({{ Istilah::repeat($b->repeat) }}):
    <span class="mono muted">{{ ($varsByBlock[$b->id] ?? collect())->implode(', ') }}</span>
    @if($b->repeat !== 'ruta')<br><span class="muted small">→ pakai akhiran <span class="mono">__n</span></span>@endif
  </p>
  @endforeach
</section>
@endsection
