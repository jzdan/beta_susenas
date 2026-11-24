@extends('layouts.app')
@php($nav = 'audit')
@section('title', 'Log Audit · Sistem SUSENAS')
@section('content')
<h1>Log Audit</h1>
<p class="muted">{{ $rows->count() }} kejadian ditampilkan (maks 300 terbaru sesuai filter).
  @if($nFailLogins > 0)
    <span class="flag warning" style="display:inline-block">{{ $nFailLogins }} percobaan login gagal 24 jam terakhir</span>
  @endif
</p>

<form class="filters" method="get">
  <select name="entity">
    <option value="">Semua jenis data</option>
    <option value="keamanan" @selected($fEntity === 'keamanan')>Keamanan (login gagal/terkunci)</option>
    @foreach ($entities as $e)
    <option value="{{ $e }}" @selected($fEntity === $e)>{{ Istilah::entity($e) }}</option>
    @endforeach
  </select>
  <select name="user">
    <option value="">Semua pelaku</option>
    @foreach ($actors as $id => $nama)<option value="{{ $id }}" @selected((string) $fUser === (string) $id)>{{ $nama }}</option>@endforeach
  </select>
  <label class="small">Dari <input type="date" name="from" value="{{ $fFrom }}"></label>
  <label class="small">Sampai <input type="date" name="to" value="{{ $fTo }}"></label>
  <input type="search" name="q" value="{{ $fQ }}" placeholder="cari detail / aksi / #id" style="min-width:180px">
  <button type="submit">Terapkan</button>
  @if($fEntity || $fUser || $fFrom || $fTo || $fQ)<a class="btn-ghost" href="{{ route('monitoring.audit') }}">Reset</a>@endif
  <a class="btn-ghost" href="{{ route('monitoring.audit', array_merge(request()->query(), ['export' => 1])) }}">⬇ CSV</a>
</form>

<div class="tablewrap tall">
<table class="tbl">
  <thead><tr><th>Waktu</th><th>Entitas</th><th>Aksi</th><th>Detail</th><th>Oleh</th></tr></thead>
  <tbody>
  @foreach ($rows as $r)
    <tr @class(['row-has-warn' => in_array($r->action, ['login-gagal', 'login-terkunci'])])>
      <td class="mono muted">{{ $r->at?->format('d-m-Y H:i:s') }}</td>
      <td>{{ Istilah::entity($r->entity) }}@if($r->entity_id) <span class="muted">#{{ $r->entity_id }}</span>@endif</td>
      <td>{{ Istilah::audit($r->action) }}</td>
      <td class="small">{{ $r->detail }}</td>
      <td>{{ $userNames[$r->by_user] ?? '—' }}</td>
    </tr>
  @endforeach
  </tbody>
</table>
</div>
@endsection
