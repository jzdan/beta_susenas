@extends('layouts.app')
@php($nav = 'jadwal')
@section('title', 'Siklus & Jadwal · Sistem SUSENAS')
@section('content')
<h1>Siklus Survei &amp; Jadwal Lapangan</h1>
<p class="muted">Tanggal lapangan dipakai dashboard untuk target kemajuan &amp; penanda tertinggal.
  Alur siklus baru: buat siklus → buat/duplikasi versi kuesioner (Admin·Metadata) → impor sampel.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <h2>Buat siklus baru</h2>
  <form method="post" action="{{ route('admin.jadwal.create') }}" class="row-form">
    @csrf
    <input name="name" placeholder="Nama (mis. SUSENAS Maret 2027)" required style="width:230px">
    <input name="period" placeholder="Periode (2027-03)" required style="width:110px" class="mono">
    <select name="status">@foreach ($statuses as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select>
    <label class="small">Mulai <input type="date" name="field_start"></label>
    <label class="small">Selesai <input type="date" name="field_end"></label>
    <button type="submit">Buat</button>
  </form>
</section>

@foreach ($cycles as $c)
<section class="panel">
  <h2>{{ $c->name }}
    <small class="muted">{{ $c->period }} · {{ $c->versions_count }} versi kuesioner · {{ $c->samples_count }} sampel</small>
    @if($c->status === 'selesai')<span class="chip s-selesai">terkunci</span>@endif
    <a class="btn-ghost" href="{{ route('admin.siklus', $c) }}">Kesiapan &amp; penutupan →</a>
  </h2>
  <form method="post" action="{{ route('admin.jadwal.save', $c) }}" class="row-form">
    @csrf
    <input name="name" value="{{ $c->name }}" required style="width:230px">
    <label class="small">Mulai
      <input type="date" name="field_start" value="{{ $c->field_start?->toDateString() }}">
    </label>
    <label class="small">Selesai
      <input type="date" name="field_end" value="{{ $c->field_end?->toDateString() }}">
    </label>
    <label class="small">Status
      <select name="status">
        @foreach ($statuses as $s)<option value="{{ $s }}" @selected($c->status === $s)>{{ ucfirst($s) }}</option>@endforeach
      </select>
    </label>
    <button type="submit">Simpan</button>
  </form>
</section>
@endforeach
@endsection
