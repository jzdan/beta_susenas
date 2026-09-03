@extends('layouts.app')
@php($nav = 'pengolahan')
@section('title', 'Pengolahan · Sistem SUSENAS')
@section('content')
<h1>Pengolahan Data (Cleaning)</h1>
<p class="muted">Tinjau dokumen berstatus <em>selesai</em>, koreksi isian bermasalah pada
  <strong>nilai bersih</strong> (nilai mentah tetap tersimpan), lalu tandai <em>bersih</em>.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

@if($queueAge['n'])
<div class="summary-counts" style="margin:10px 0">
  <span class="cnt">{{ $queueAge['n'] }}</span> dokumen menunggu ·
  rata-rata <strong>{{ $queueAge['avg'] }}</strong> hari ·
  terlama <strong>{{ $queueAge['max'] }}</strong> hari
  @if($queueAge['stale'])<span class="flag warning" style="display:inline-block">{{ $queueAge['stale'] }} mengendap ≥{{ $staleDays }} hari</span>@endif
</div>
@endif

@if(count($ruleAgg))
<section class="panel">
  <h2>Rekap temuan validasi <small class="muted">({{ $totalDocs }} dokumen dalam cakupan Anda)</small></h2>
  <table class="tbl">
    <thead><tr><th>Kode</th><th>Tingkat</th><th>Deskripsi</th><th class="num">Dokumen</th><th class="num">Total temuan</th><th></th></tr></thead>
    <tbody>
    @foreach ($ruleAgg as $ra)
      <tr @class(['row-has-warn' => $fRule === $ra->code])>
        <td class="mono">{{ $ra->code }}</td>
        <td><span class="flag {{ $ra->severity === 'error' ? 'error' : 'warning' }}" style="display:inline-block">{{ $ra->severity }}</span></td>
        <td class="small">{{ $ra->message }}</td>
        <td class="num">{{ $ra->n_doc }}</td>
        <td class="num">{{ $ra->n_hit }}</td>
        <td class="right"><a class="btn-ghost" href="{{ route('pengolahan.index', ['rule' => $ra->code, 'status' => $fStatus]) }}">Lihat</a></td>
      </tr>
    @endforeach
    </tbody>
  </table>
</section>
@endif

<div class="row-form">
  <form class="filters" method="get">
    <select name="status" onchange="this.form.submit()">
      <option value="">Semua status</option>
      @foreach ($statuses as $k => $lbl)<option value="{{ $k }}" @selected($fStatus === $k)>{{ $lbl }}</option>@endforeach
    </select>
    <input type="search" name="q" value="{{ $fQ }}" placeholder="cari wilayah / BS / ruta / KRT / #dok" style="min-width:220px">
    <button type="submit">Cari</button>
    @if($fRule)<input type="hidden" name="rule" value="{{ $fRule }}">@endif
    @if($fQ)<a class="btn-ghost" href="{{ route('pengolahan.index', ['status' => $fStatus, 'rule' => $fRule]) }}">✕</a>@endif
  </form>
  @if($fRule)<a class="btn-ghost" href="{{ route('pengolahan.index', ['status' => $fStatus, 'q' => $fQ]) }}">✕ filter aturan: {{ $fRule }}</a>@endif
  @if(in_array(auth()->user()->role, ['pml','admin']))
  <form method="post" action="{{ route('pengolahan.revalidate', ['status' => $fStatus, 'rule' => $fRule, 'q' => $fQ]) }}"
        onsubmit="return confirm('Jalankan ulang semua aturan validasi untuk dokumen non-bersih dalam cakupan Anda?')">
    @csrf
    <button type="submit" class="btn-ghost">↻ Jalankan ulang aturan</button>
  </form>
  @endif
</div>

@php($canBulk = in_array(auth()->user()->role, ['pml','editor','admin']))
@php($eligible = fn($r) => in_array($r['doc']->status, ['selesai','dikembalikan']) && $r['err'] === 0)
<form method="post" action="{{ route('pengolahan.bulk-clean', ['status' => $fStatus, 'rule' => $fRule, 'q' => $fQ]) }}"
      onsubmit="return confirm('Tandai bersih semua dokumen terpilih?')">
@csrf
@if($canBulk && $rows->contains($eligible))
<div class="row-form" style="margin-bottom:6px">
  <label class="small"><input type="checkbox" id="pick-all"> pilih semua yang bisa dibersihkan</label>
  <button type="submit" class="btn-ghost" id="bulk-btn" disabled>Tandai bersih terpilih (<span id="bulk-n">0</span>)</button>
</div>
@endif
<div class="tablewrap tall">
<table class="tbl">
  <thead><tr>@if($canBulk)<th></th>@endif<th>Dokumen</th><th>Wilayah / Ruta</th><th>Status</th><th class="num">Menunggu</th><th class="num">Error</th><th class="num">Warning <span class="muted">blm/total</span></th><th class="num">Catatan</th><th></th></tr></thead>
  <tbody>
  @forelse ($rows as $r)
    <tr>
      @if($canBulk)<td>@if($eligible($r))<input type="checkbox" class="pick" name="ids[]" value="{{ $r['doc']->id }}">@endif</td>@endif
      <td class="mono">#{{ $r['doc']->id }}</td>
      <td>{{ $r['wil'] }} <span class="mono muted">· BS {{ $r['doc']->no_bs }} · {{ $r['doc']->no_ruta }}</span><br>
        <span class="muted small">{{ $r['doc']->krt_name }}</span></td>
      <td><span class="chip d-{{ $r['doc']->status }}">{{ $r['doc']->status }}</span></td>
      <td class="num">
        @if($r['wait_days'] === null)<span class="muted">—</span>
        @elseif($r['wait_days'] >= $staleDays)<span class="flag warning" style="display:inline-block">{{ $r['wait_days'] }} hari</span>
        @else {{ $r['wait_days'] }} hari @endif
      </td>
      <td class="num">@if($r['err'])<span class="flag error" style="display:inline-block">{{ $r['err'] }}</span>@else 0 @endif</td>
      <td class="num">
        @if($r['warn'])
          <span class="flag warning" style="display:inline-block">{{ $r['warn_open'] }}<span class="muted">/{{ $r['warn'] }}</span></span>
        @else 0 @endif
      </td>
      <td class="num">@if($r['notes'])💬 {{ $r['notes'] }}@else <span class="muted">—</span>@endif</td>
      <td class="right"><a class="btn-ghost" href="{{ route('pengolahan.show', $r['doc']) }}">Buka</a></td>
    </tr>
  @empty
    <tr><td colspan="9" class="muted">Tidak ada dokumen.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
</form>
<p class="muted">{{ $rows->count() }} dokumen{{ $fQ ? ' cocok dengan "'.$fQ.'"' : '' }}.</p>

@if($canBulk)
@push('scripts')
<script>
(function () {
  var btn = document.getElementById("bulk-btn");
  if (!btn) return;
  var picks = function () { return Array.prototype.slice.call(document.querySelectorAll(".pick")); };
  function sync() {
    var n = picks().filter(function (c) { return c.checked; }).length;
    document.getElementById("bulk-n").textContent = n;
    btn.disabled = n === 0;
  }
  var all = document.getElementById("pick-all");
  if (all) all.addEventListener("change", function () {
    picks().forEach(function (c) { c.checked = all.checked; });
    sync();
  });
  picks().forEach(function (c) { c.addEventListener("change", sync); });
})();
</script>
@endpush
@endif
@endsection
