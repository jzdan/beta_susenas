@extends('layouts.app')
@php($nav = 'jadwal')
@section('title', "Siklus {$cycle->name} · Sistem SUSENAS")
@section('content')
<p class="no-print"><a class="btn-ghost" href="{{ route('admin.jadwal') }}">← Jadwal siklus</a>
  <a class="btn-ghost" href="{{ route('admin.siklus.persiapan', $cycle) }}">Persiapan periode</a>
  <button type="button" class="btn-ghost" onclick="window.print()">Cetak laporan</button></p>
<div class="print-doc">
<h1>{{ $cycle->name }}
  <span class="chip s-{{ $cycle->status === 'selesai' ? 'selesai' : 'proses' }}">{{ Istilah::cycleStatus($cycle->status) }}</span>
</h1>
<p class="muted">Periode {{ $cycle->period }} ·
  versi: {{ $versions->isEmpty() ? '—' : $versions->implode(', ') }} ·
  dicetak {{ now()->format('d-m-Y H:i') }}</p>

@if(session('ok'))<div class="alert no-print" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif

<section class="panel no-print">
  <h2>Pintasan</h2>
  <div class="grid2">
    <div class="panel" style="margin:0">
      <h2>Instrumen &amp; validasi</h2>
      @if($versionObjs->isEmpty())
        <p class="muted small">Belum ada versi kuesioner untuk siklus ini.</p>
      @else
        @foreach ($versionObjs as $v)
          <p class="small" style="margin:4px 0">
            <span class="mono">{{ $v->code }}</span> —
            <a href="{{ route('admin.metadata', ['qv' => $v->id]) }}">Metadata</a> ·
            <a href="{{ route('admin.aturan', ['qv' => $v->id]) }}">Aturan Validasi</a> ·
            <a href="{{ route('admin.harga', ['qv' => $v->id]) }}">Harga &amp; Kalori</a>
          </p>
        @endforeach
      @endif
    </div>
    <div class="panel" style="margin:0">
      <h2>Sampel &amp; Pencacah Lapangan</h2>
      <p class="small" style="margin:4px 0">
        <a href="{{ route('monitoring.samples', ['cycle' => $cycle->id]) }}">Daftar Sampel siklus ini →</a></p>
      <p class="small" style="margin:4px 0">
        <a href="{{ route('admin.impor.sampel') }}">Impor Sampel →</a></p>
    </div>
    <div class="panel" style="margin:0">
      <h2>Wilayah kerja</h2>
      <p class="small" style="margin:4px 0"><a href="{{ route('admin.impor.wilayah') }}">Impor Wilkerstat →</a></p>
    </div>
    <div class="panel" style="margin:0">
      <h2>Pengguna</h2>
      <p class="small" style="margin:4px 0"><a href="{{ route('admin.pengguna') }}">Kelola Pengguna →</a></p>
    </div>
  </div>
</section>

<section class="panel">
  <h2>Respons &amp; disposisi</h2>
  <div class="summary-counts">
    <span class="cnt">{{ $response['rate'] }}%</span> angka respons ·
    <span class="cnt">{{ $response['responded'] }}</span>/{{ $response['eligible'] }} yang memenuhi syarat terespons ·
    <span class="cnt warn">{{ $response['nonresponse'] }}</span> non-respons ·
    <span class="muted">{{ $response['ineligible'] }} tidak memenuhi syarat · {{ $response['replaced'] }} diganti</span>
  </div>
</section>

<div class="grid2">
  <section class="panel">
    <h2>Tahap sampel ({{ $sTotal }})</h2>
    <div class="tablewrap">
    <table class="tbl mini"><tbody>
      @foreach (Tahap::LABELS as $k => $lbl)
      <tr><td><span class="chip t-{{ $k }}">{{ $lbl }}</span></td><td class="num">{{ $tahap[$k] }}</td>
        <td><div class="bar"><i style="width: {{ $sTotal ? round(100 * $tahap[$k] / $sTotal, 1) : 0 }}%"></i></div></td></tr>
      @endforeach
      <tr class="{{ $unresolved ? 'row-has-warn' : '' }}">
        <td><strong>Belum tuntas di lapangan</strong></td><td class="num"><strong>{{ $unresolved }}</strong></td><td></td></tr>
      <tr class="{{ $notBersih ? 'row-has-warn' : '' }}">
        <td><strong>Terisi tapi belum bersih</strong></td><td class="num"><strong>{{ $notBersih }}</strong></td><td></td></tr>
      <tr class="{{ $errDocs ? 'row-has-warn' : '' }}">
        <td>Masih ada kesalahan</td><td class="num">{{ $errDocs }}</td><td></td></tr>
    </tbody></table>
    </div>
  </section>

  <section class="panel">
    <h2>Temuan tersering</h2>
    @if($topRules->isEmpty())
      <p class="muted">Belum ada temuan.</p>
    @else
    <div class="tablewrap">
    <table class="tbl mini">
      <thead><tr><th>Kode</th><th>Tingkat</th><th class="num">Sampel</th></tr></thead>
      <tbody>
      @foreach ($topRules as $tr)
      <tr><td class="mono">{{ $tr->code }}</td>
        <td><span class="flag {{ $tr->severity === 'error' ? 'error' : 'warning' }}">{{ Istilah::severity($tr->severity) }}</span></td>
        <td class="num">{{ $tr->n_doc }}</td></tr>
      @endforeach
      </tbody>
    </table>
    </div>
    @endif
  </section>
</div>

<section class="panel no-print">
  <h2>Revalidasi</h2>
  <p class="muted small">Jalankan ulang mesin aturan untuk semua {{ $dTotal }} sampel terisi siklus ini —
    lakukan setelah menyunting aturan / agregat / nilai turunan.</p>
  <form method="post" action="{{ route('admin.siklus.revalidate', $cycle) }}"
    onsubmit="return confirm('Revalidasi seluruh sampel siklus ini?')">
    @csrf<button type="submit" class="btn-ghost">↻ Revalidasi seluruh siklus</button>
  </form>
</section>

<section class="panel no-print">
  <h2>Penutupan siklus</h2>
  @if($cycle->isLocked())
    <p>Siklus <strong>terkunci</strong>. Entri &amp; pengolahan untuk siklus ini menolak perubahan data.</p>
    <form method="post" action="{{ route('admin.siklus.unlock', $cycle) }}"
      onsubmit="return confirm('Buka kembali kunci siklus ini?')">
      @csrf<button type="submit" class="btn-ghost">Buka kembali kunci</button>
    </form>
  @else
    @if($ready)
      <p class="muted">Semua sampel tuntas &amp; semua sampel bersih tanpa kesalahan, siap dikunci.</p>
    @else
      <div class="alert err">Belum sepenuhnya siap:
        <ul class="small">
          @if($unresolved)<li>{{ $unresolved }} sampel belum tuntas</li>@endif
          @if($notBersih)<li>{{ $notBersih }} sampel belum bersih</li>@endif
          @if($errDocs)<li>{{ $errDocs }} sampel masih ada kesalahan</li>@endif
        </ul>
        Mengunci tetap bisa dilakukan (mis. non-respons yang memang final), tapi data tak bisa diubah lagi setelahnya.
      </div>
    @endif
    <form method="post" action="{{ route('admin.siklus.lock', $cycle) }}"
      onsubmit="return confirm('Kunci siklus? Entri & pengolahan untuk siklus ini akan menolak perubahan.')">
      @csrf<button type="submit">Kunci siklus (tandai selesai)</button>
    </form>
  @endif
</section>

<p class="muted small print-foot">Laporan internal siklus, bukan angka resmi.</p>
</div>
@endsection
