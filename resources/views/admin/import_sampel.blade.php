@extends('layouts.app')
@php($nav = 'imsampel')
@section('title', 'Impor Daftar Sampel · Sistem SUSENAS')
@section('content')
<x-back :href="request('cycle') ? route('monitoring.samples', ['cycle' => request('cycle')]) : route('periode.index')" smart />
<h1>Daftar Sampel Rumah Tangga</h1>
<p class="muted">Untuk satu responden pakai form <strong>Tambah satu sampel</strong>; untuk banyak sekaligus
  pakai <strong>Impor CSV</strong> (kolom wajib <span class="mono">wilayah_code, no_bs, no_ruta</span>,
  opsional <span class="mono">krt_name, petugas, pml</span>; dua terakhir diisi nama pengguna untuk masuk).
  Sampel duplikat dan kode desa yang tidak ada di master wilayah akan dilewati/ditolak.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

@if($hasil)
<div class="alert {{ $hasil['dilewati'] && !$hasil['dibuat'] ? 'err' : '' }}">
  <strong>{{ $hasil['dibuat'] }}</strong> sampel ditambahkan ke siklus {{ $hasil['cycle']->name }}.
  @if($hasil['dilewati'])<br>Dilewati {{ count($hasil['dilewati']) }}:
    <ul>@foreach ($hasil['dilewati'] as $d)<li class="small">{{ $d }}</li>@endforeach</ul>@endif
</div>
@endif

<section class="panel">
  <h2>Tambah satu sampel</h2>
  <p class="muted small">Untuk menambah beberapa sekaligus, pakai impor CSV di bawah.</p>
  <form method="post" action="{{ route('admin.sampel.simpan') }}" class="row-form">
    @csrf
    <label class="small">Siklus
      <select name="cycle_id" required>
        <option value="">—</option>
        @foreach ($cycles as $c)<option value="{{ $c->id }}" @selected((string) old('cycle_id', request('cycle')) === (string) $c->id)>{{ $c->name }} ({{ $c->period }})</option>@endforeach
      </select>
    </label>
    <label class="small">Kode desa
      <select name="wilayah_code" id="wilayah_code" onchange="filterPetugasByKecamatan()" required>
        <option value="">—</option>
        @foreach ($kecGroups as $g)
          <optgroup label="{{ $g['kec']->code }} — {{ $g['kec']->name }}">
            @foreach ($g['desa'] as $d)
              <option value="{{ $d->code }}" data-kec="{{ $g['kec']->code }}"
                @selected(old('wilayah_code') === $d->code)>{{ $d->code }} — {{ $d->name }}</option>
            @endforeach
          </optgroup>
        @endforeach
      </select>
    </label>
    <label class="small">Nomor Blok Sensus <input name="no_bs" value="{{ old('no_bs') }}" class="mono" style="width:90px" placeholder="001B" required></label>
    <label class="small">Nomor Rumah Tangga <input name="no_ruta" value="{{ old('no_ruta') }}" class="mono" style="width:70px" placeholder="001" required></label>
    <label class="small">Nama Kepala Rumah Tangga <input name="krt_name" value="{{ old('krt_name') }}" style="min-width:160px"></label>
    <label class="small">Petugas
      <select name="petugas_id" id="petugas_id">
        <option value="">—</option>
      </select>
    </label>
    <label class="small">Pengawas Lapangan
      <select name="pml_id">
        <option value="">—</option>
        @foreach ($pmls as $id => $name)<option value="{{ $id }}" @selected((string) old('pml_id') === (string) $id)>{{ $name }}</option>@endforeach
      </select>
    </label>
    <button type="submit">Tambah</button>
  </form>
  <p class="muted small">Daftar petugas otomatis tersaring ke kecamatan dari kode desa yang dipilih
    (petugas tanpa cakupan wilayah selalu muncul).</p>
</section>

<section class="panel">
  <h2>Impor banyak sekaligus (CSV)</h2>
  <form method="post" action="{{ route('admin.impor.sampel.run') }}" class="stack-form">
    @csrf
    <label>Siklus survei
      <select name="cycle_id" required>
        @foreach ($cycles as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->period }})</option>@endforeach
      </select>
    </label>
    <label>Data CSV
      <textarea name="data" rows="12" class="mono" required
        placeholder="wilayah_code,no_bs,no_ruta,krt_name,petugas,pml&#10;3526010001,001B,001,Sugianto,ppl_ani,rahmawati"></textarea>
    </label>
    <button type="submit">Impor</button>
  </form>
</section>

<section class="panel">
  <h2>Contoh</h2>
<pre class="schema">wilayah_code,no_bs,no_ruta,krt_name,petugas,pml
3526010001,001B,001,Sugianto,ppl_ani,rahmawati
3526010001,001B,002,Hj. Maryam,ppl_ani,rahmawati</pre>
</section>
@push('scripts')
<script>
const OPERATORS = @json($operators);
const OLD_PETUGAS = @json(old('petugas_id'));

function filterPetugasByKecamatan() {
  const desaSel = document.getElementById('wilayah_code');
  const chosen = desaSel.options[desaSel.selectedIndex];
  const kec = chosen ? chosen.dataset.kec : null;

  const petSel = document.getElementById('petugas_id');
  const keep = kec ? OPERATORS.filter(o => !o.scope || o.scope === kec) : OPERATORS;

  petSel.innerHTML = '<option value="">—</option>';
  keep.forEach(o => {
    const opt = document.createElement('option');
    opt.value = o.id;
    opt.textContent = o.name + (o.scope ? '' : ' (semua kecamatan)');
    if (OLD_PETUGAS && String(OLD_PETUGAS) === String(o.id)) opt.selected = true;
    petSel.appendChild(opt);
  });
}
document.addEventListener('DOMContentLoaded', filterPetugasByKecamatan);
</script>
@endpush
@endsection
