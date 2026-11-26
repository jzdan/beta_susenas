@extends('layouts.app')
@php($nav = 'meta')
@section('title', "Rincian {$q->var_name} · Sistem SUSENAS")
@section('content')
<x-back :href="route('admin.blok', $q->block_id)" label="Kembali ke Blok {{ $q->block->code }}" />
<h1>Sunting rincian <span class="mono">{{ $q->var_name }}</span></h1>
@include('admin.partials._ro_version', ['roQv' => $q->block->qversion])

@if ($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <form method="post" action="{{ route('admin.rincian.update', $q) }}" class="stack-form">
    @csrf
    <div class="row-form">
      <label>Kode <input name="code_rincian" value="{{ $q->code_rincian }}" required></label>
      <label>Variabel <input name="var_name" value="{{ $q->var_name }}" class="mono" required></label>
      <label>Tipe
        <select name="qtype">@foreach ($qtypes as $t)<option value="{{ $t }}" @selected($q->qtype === $t)>{{ Istilah::qtype($t) }}</option>@endforeach</select>
      </label>
      <label>Satuan <input name="unit" value="{{ $q->unit }}"></label>
    </div>
    <label>Label <input name="label" value="{{ $q->label }}" required></label>
    <label>Lewati bila <input name="skip_expr" value="{{ $q->skip_expr }}" class="mono"></label>
    @if($q->block->repeat !== 'ruta')
    <p class="muted small">Blok ini berulang per baris, jadi syarat dihitung <strong>per baris</strong>: boleh memakai isian lain di baris yang sama
      (mis. <span class="mono">n({{ $q->block->questions->first()?->var_name ?? 'umur' }}) &lt; 5</span>), data patokan baris
      <span class="mono">ref_…</span>, dan nomor baris <span class="mono">_row_index</span>. Sel yang dilewati dikunci, tidak diperiksa aturan, dan tidak ikut dihitung.</p>
    @endif
    <label>Teks bantuan <input name="help_text" value="{{ $q->help_text }}"></label>
    <div class="row-form">
      <button type="submit">Simpan</button>
      <a class="btn-ghost" href="{{ route('admin.blok', $q->block_id) }}">Batal</a>
    </div>
  </form>
  <form method="post" action="{{ route('admin.rincian.del', $q) }}" onsubmit="return confirm('Hapus rincian ini?')" class="mt">
    @csrf<button class="btn-ghost">Hapus rincian</button>
  </form>
</section>

@if($q->qtype === 'code')
<section class="panel">
  <h2>Pilihan jawaban</h2>
  @if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th></th><th>Kode</th><th>Label</th><th></th></tr></thead>
    <tbody>
    @forelse ($options->sortBy('order') as $o)
      <tr>
        <td class="nowrap">
          <form method="post" action="{{ route('admin.opsi.order', $o) }}" style="display:inline">@csrf
            <button class="btn-ghost" name="arah" value="naik" @disabled($loop->first)>↑</button>
            <button class="btn-ghost" name="arah" value="turun" @disabled($loop->last)>↓</button>
          </form>
        </td>
        <td class="mono">{{ $o->code }}</td><td>{{ $o->label }}</td>
        <td class="right"><form method="post" action="{{ route('admin.opsi.del', $o) }}">@csrf<button class="btn-ghost">Hapus</button></form></td>
      </tr>
    @empty
      <tr><td colspan="4" class="muted">Belum ada pilihan.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  <form method="post" action="{{ route('admin.opsi.add', $q) }}" class="row-form mt">
    @csrf
    <input name="code" placeholder="Kode (mis. 1)" required style="width:100px">
    <input name="label" placeholder="Label pilihan" required style="flex:1">
    <button type="submit">Tambah pilihan</button>
  </form>

  <details class="mt">
    <summary style="cursor:pointer">Tambah banyak sekaligus</summary>
    <form method="post" action="{{ route('admin.opsi.bulk', $q) }}" class="stack-form mt">
      @csrf
      <label>Satu pilihan per baris — <span class="mono">kode,label</span>
        <textarea name="data" rows="6" class="mono" required
          placeholder="1,Kepala rumah tangga&#10;2,Istri/suami&#10;3,Anak"></textarea>
      </label>
      <button type="submit" class="btn-ghost">Impor pilihan</button>
    </form>
  </details>
</section>
@endif
@endsection
