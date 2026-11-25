@extends('layouts.app')
@php($nav = 'jadwal')
@section('title', 'Siklus & Jadwal · Sistem SUSENAS')
@section('content')
<x-back :href="route('periode.index')" label="Kembali ke Periode Survei" />
<h1>Siklus Survei &amp; Jadwal Lapangan</h1>
<p class="muted">Alur siklus baru: buat siklus (status Persiapan), siapkan versi kuesioner dan sampel,
  lalu buka lapangan dari halaman <em>Persiapan periode</em>.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <h2>Buat siklus baru</h2>
  <form method="post" action="{{ route('admin.jadwal.create') }}" class="row-form">
    @csrf
    <input name="name" placeholder="Nama (mis. SUSENAS Maret 2027)" required style="width:230px">
    <input name="period" placeholder="Periode (2027-03)" required style="width:110px" class="mono">
    <select name="status">@foreach ($statuses as $s)<option value="{{ $s }}">{{ Istilah::cycleStatus($s) }}: {{ Istilah::CYCLE_STATUS_HELP[$s] }}</option>@endforeach</select>
    <label class="small">Mulai <input type="date" name="field_start"></label>
    <label class="small">Selesai <input type="date" name="field_end"></label>
    <button type="submit">Buat</button>
  </form>
</section>

@foreach ($cyclesByYear as $year => $group)
<details class="rule-group" @if($loop->first) open @endif>
  <summary style="cursor:pointer;font-weight:600;font-size:1.05rem;padding:6px 0">
    {{ $year }} <span class="muted small">— {{ $group->count() }} siklus</span>
  </summary>
  @foreach ($group as $c)
  <section class="panel" style="margin-top:10px">
    <h2>{{ $c->name }}
      <small class="muted">{{ $c->period }} · {{ $c->versions_count }} versi kuesioner · {{ $c->samples_count }} sampel</small>
      @if($c->status === 'selesai')<span class="chip s-selesai">terkunci</span>@endif
      @if($c->status === 'persiapan')<a class="btn-ghost" href="{{ route('admin.siklus.persiapan', $c) }}">Persiapan periode →</a>@endif
      <a class="btn-ghost" href="{{ route('admin.siklus', $c) }}">Laporan &amp; penutupan →</a>
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
          @foreach ($statuses as $s)<option value="{{ $s }}" @selected($c->status === $s)>{{ Istilah::cycleStatus($s) }}: {{ Istilah::CYCLE_STATUS_HELP[$s] }}</option>@endforeach
        </select>
      </label>
      <button type="submit">Simpan</button>
    </form>
  </section>
  @endforeach
</details>
@endforeach
@endsection
