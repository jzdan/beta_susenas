@extends('layouts.app')
@php($nav = 'periode')
@section('title', 'Peserta '.$hubCycle->label().' TW'.$hubCycle->quarter().' '.$hubCycle->year().' · Sistem SUSENAS')
@section('content')
@include('periode._hub_header')

<section class="panel">
  <h2>Peserta <small class="muted">diambil dari penugasan sampel; akun dikelola di menu Pengguna</small></h2>
  @if($belumDitugaskan)
    <div class="alert">{{ $belumDitugaskan }} sampel belum punya Pencacah Lapangan.
      <a href="{{ route('monitoring.samples', ['cycle' => $hubCycle->id, 'petugas' => 'none']) }}">Tugaskan →</a></div>
  @endif
  @if($peserta->isEmpty())
    <p class="muted">Belum ada sampel yang ditugaskan di periode ini.</p>
  @else
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>Nama</th><th>Peran</th><th>Wilayah</th><th class="num">Sampel</th><th>Selesai dicacah</th><th class="num">Dikembalikan</th></tr></thead>
    <tbody>
    @foreach ($peserta as $p)
      <tr>
        <td>{{ $p['user']->name ?? '(akun terhapus)' }}</td>
        <td>{{ $p['peran'] }}</td>
        <td class="small">{{ $p['wilayah'] }}</td>
        <td class="num">{{ $p['total'] }}</td>
        <td style="min-width:150px"><div class="bar"><i style="width: {{ $p['total'] ? round(100 * $p['selesai'] / $p['total']) : 0 }}%"></i></div>
          <span class="small muted">{{ $p['selesai'] }} / {{ $p['total'] }}</span></td>
        <td class="num">{{ $p['dikembalikan'] ?: '—' }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  @endif
</section>
@endsection
