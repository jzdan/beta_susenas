@extends('layouts.app')
@php($nav = 'jadwal')
@section('title', "Siklus {$cycle->name} · Sistem SUSENAS")
@section('content')
<p class="no-print"><a class="btn-ghost" href="{{ route('admin.jadwal') }}">← Jadwal siklus</a>
  <button type="button" class="btn-ghost" onclick="window.print()">Cetak laporan</button></p>
<div class="print-doc">
<h1>{{ $cycle->name }}
  <span class="chip s-{{ $cycle->status === 'selesai' ? 'selesai' : 'proses' }}">{{ $cycle->status }}</span>
</h1>
<p class="muted">Periode {{ $cycle->period }} ·
  versi: {{ $versions->isEmpty() ? '—' : $versions->implode(', ') }} ·
  dicetak {{ now()->format('d-m-Y H:i') }}</p>

@if(session('ok'))<div class="alert no-print" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif

<section class="panel no-print" style="border-style:dashed;border-color:var(--accent);background:var(--accent-soft)">
  <h2 style="color:var(--accent-strong)">⚡ Aksi cepat — untuk siklus ini</h2>
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
      <h2>Sampel &amp; PCL</h2>
      <p class="small" style="margin:4px 0">
        <a href="{{ route('monitoring.samples', ['cycle' => $cycle->id]) }}">Daftar Sampel siklus ini →</a></p>
      <p class="small" style="margin:4px 0">
        <a href="{{ route('admin.impor.sampel') }}">Impor Sampel →</a></p>
    </div>
    <div class="panel" style="margin:0">
      <h2>Wilayah kerja</h2>
      <p class="muted small" style="margin:4px 0 6px">Master wilayah kabupaten — dipakai bersama, tidak khusus siklus ini.</p>
      <p class="small" style="margin:4px 0"><a href="{{ route('admin.impor.wilayah') }}">Impor Wilkerstat →</a></p>
    </div>
    <div class="panel" style="margin:0">
      <h2>Pengguna</h2>
      <p class="muted small" style="margin:4px 0 6px">Akun PCL/PML/PJ — dipakai bersama, tidak khusus siklus ini.</p>
      <p class="small" style="margin:4px 0"><a href="{{ route('admin.pengguna') }}">Kelola Pengguna →</a></p>
    </div>
  </div>
</section>

<section class="panel">
  <h2>Respons &amp; disposisi</h2>
  <div class="summary-counts">
    <span class="cnt">{{ $response['rate'] }}%</span> response rate ·
    <span class="cnt">{{ $response['responded'] }}</span>/{{ $response['eligible'] }} eligible terespons ·
    <span class="cnt warn">{{ $response['nonresponse'] }}</span> non-respons ·
    <span class="muted">{{ $response['ineligible'] }} tidak eligible · {{ $response['replaced'] }} diganti</span>
  </div>
</section>

<div class="grid2">
  <section class="panel">
    <h2>Sampel ({{ $sTotal }})</h2>
    <table class="tbl mini"><tbody>
      @foreach ($statusLabels as $k => $lbl)
      <tr><td>{{ $lbl }}</td><td class="num">{{ $sByStatus[$k] ?? 0 }}</td></tr>
      @endforeach
      <tr class="{{ $unresolved ? 'row-has-warn' : '' }}">
        <td><strong>Belum tuntas (belum/proses)</strong></td><td class="num"><strong>{{ $unresolved }}</strong></td>
      </tr>
    </tbody></table>
  </section>

  <section class="panel">
    <h2>Dokumen ({{ $dTotal }})</h2>
    <table class="tbl mini"><tbody>
      @foreach (['draft','selesai','dikembalikan','bersih'] as $k)
      <tr><td>{{ $k }}</td><td class="num">{{ $dByStatus[$k] ?? 0 }}</td></tr>
      @endforeach
      <tr class="{{ $notBersih ? 'row-has-warn' : '' }}">
        <td><strong>Belum bersih</strong></td><td class="num"><strong>{{ $notBersih }}</strong></td></tr>
      <tr class="{{ $errDocs ? 'row-has-warn' : '' }}">
        <td>Dokumen masih ada error</td><td class="num">{{ $errDocs }}</td></tr>
    </tbody></table>
  </section>
</div>

<div class="grid2">
  <section class="panel">
    <h2>Alur pengolahan</h2>
    <table class="tbl mini"><tbody>
      @php($ft = max(1, collect($funnel)->sum('n')))
      @foreach ($funnel as $f)
      <tr><td>{{ $f['label'] }}</td><td class="num">{{ $f['n'] }}</td>
        <td><div class="bar"><i style="width: {{ round(100 * $f['n'] / $ft, 1) }}%"></i></div></td></tr>
      @endforeach
    </tbody></table>
  </section>

  <section class="panel">
    <h2>Temuan tersering</h2>
    @if($topRules->isEmpty())
      <p class="muted">Belum ada temuan.</p>
    @else
    <table class="tbl mini">
      <thead><tr><th>Kode</th><th>Sev</th><th class="num">Dok</th></tr></thead>
      <tbody>
      @foreach ($topRules as $tr)
      <tr><td class="mono">{{ $tr->code }}</td>
        <td><span class="flag {{ $tr->severity === 'error' ? 'error' : 'warning' }}">{{ $tr->severity }}</span></td>
        <td class="num">{{ $tr->n_doc }}</td></tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </section>
</div>

<section class="panel no-print">
  <h2>Revalidasi</h2>
  <p class="muted small">Jalankan ulang mesin aturan untuk semua {{ $dTotal }} dokumen siklus ini —
    lakukan setelah menyunting aturan / agregat / nilai turunan.</p>
  <form method="post" action="{{ route('admin.siklus.revalidate', $cycle) }}"
    onsubmit="return confirm('Revalidasi seluruh dokumen siklus ini?')">
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
      <p class="muted">Semua sampel tuntas &amp; semua dokumen bersih tanpa error — siap dikunci.</p>
    @else
      <div class="alert err">Belum sepenuhnya siap:
        <ul class="small">
          @if($unresolved)<li>{{ $unresolved }} sampel belum tuntas</li>@endif
          @if($notBersih)<li>{{ $notBersih }} dokumen belum bersih</li>@endif
          @if($errDocs)<li>{{ $errDocs }} dokumen masih ada error</li>@endif
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

<p class="muted small print-foot">Laporan internal siklus — rekap pra-pengolahan, bukan angka resmi.</p>
</div>
@endsection
