@extends('layouts.app')
@php($nav = 'kinerja')
@section('title', 'Kinerja Tim · Sistem SUSENAS')
@section('content')
<h1>Kinerja Tim
  @if($cycle)<small class="muted">TW{{ $cycle->quarter() }} {{ $cycle->year() }} · {{ $cycle->label() }}</small>@else<small class="muted">semua siklus</small>@endif</h1>
@include('monitoring._cycle_pick')
<p class="muted">Produktivitas entri &amp; pengolahan dalam cakupan wilayah Anda
  ({{ $totalDocs }} sampel). Angka indikatif untuk pembinaan, bukan penilaian formal.</p>

<section class="panel">
  <h2>Entri — per petugas</h2>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr>
      <th>Petugas</th><th class="num">Sampel</th><th class="num">Difinalisasi</th>
      <th class="num">Bersih</th><th class="num">Dikembalikan</th>
      <th class="num">Rata² hari entri</th><th class="num">Rata² peringatan</th>
    </tr></thead>
    <tbody>
    @forelse ($entriRows as $r)
      <tr>
        <td><span class="avatar-row"><x-avatar :name="$r['name']" :size="26" /><span class="nm">{{ $r['name'] }}</span></span></td>
        <td class="num">{{ $r['total'] }}</td>
        <td class="num">{{ $r['submit'] }}</td>
        <td class="num">{{ $r['bersih'] }}</td>
        <td class="num">@if($r['dikembalikan'])<span class="flag warning" style="display:inline-block">{{ $r['dikembalikan'] }}</span>@else 0 @endif</td>
        <td class="num">{{ $r['avg_entry_days'] ?? '—' }}</td>
        <td class="num">{{ $r['avg_warn'] ?? '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="7" class="muted">Belum ada data.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <h2>Pengolahan — per pemeriksa</h2>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr>
      <th>Pemeriksa</th><th class="num">Dibersihkan</th><th class="num">Koreksi</th>
      <th class="num">Peringatan ditinjau</th><th class="num">Dikembalikan</th>
      <th class="num">Rata² hari tunggu→bersih</th>
    </tr></thead>
    <tbody>
    @forelse ($olahRows as $r)
      <tr>
        <td><span class="avatar-row"><x-avatar :name="$r['name']" :size="26" /><span class="nm">{{ $r['name'] }}</span></span></td>
        <td class="num">{{ $r['bersih'] }}</td>
        <td class="num">{{ $r['koreksi'] }}</td>
        <td class="num">{{ $r['ditinjau'] }}</td>
        <td class="num">{{ $r['dikembalikan'] }}</td>
        <td class="num">{{ $r['avg_lead_days'] ?? '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted">Belum ada data.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
