@extends('layouts.app')
@php($nav = 'sampel')
@section('title', 'Daftar Sampel · Sistem SUSENAS')
@section('content')
@php($hubC = $fCycle && in_array(auth()->user()->role, ['pj', 'pml', 'admin'], true) ? \App\Models\SurveyCycle::find($fCycle) : null)
@if($hubC)
@include('periode._hub_header', \App\Http\Controllers\PeriodeController::hubContext($hubC) + ['hubTab' => 'sampel'])
@if(auth()->user()->role === 'admin')
<p class="row-form" style="margin:0 0 10px">
  <a class="btn-ghost" href="{{ route('admin.impor.sampel', ['cycle' => $hubC->id]) }}">Impor sampel</a>
  <a class="btn-ghost" href="{{ route('admin.impor.dokumen', ['cycle' => $hubC->id]) }}">Impor isian (CAPI)</a>
</p>
@endif
@else
<h1>Daftar Sampel Rumah Tangga</h1>
@endif

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<form class="filters" method="get">
  <select name="tahap">
    <option value="">Semua tahap</option>
    @foreach (Tahap::LABELS as $k => $lbl)
    <option value="{{ $k }}" @selected($fTahap === $k)>{{ $lbl }}</option>
    @endforeach
  </select>
  <select name="wil">
    <option value="">Semua kecamatan</option>
    @foreach ($kecs as $k)
    <option value="{{ $k->code }}" @selected($fWil === $k->code)>{{ $k->name }}</option>
    @endforeach
  </select>
  <select name="cycle">
    <option value="">Semua periode</option>
    @foreach ($cycles->groupBy(fn ($c) => $c->year().' TW'.$c->quarter()) as $grp => $cs)
      <optgroup label="{{ $grp }}">
        @foreach ($cs as $c)
          <option value="{{ $c->id }}" @selected((string) $fCycle === (string) $c->id)>TW{{ $c->quarter() }} {{ $c->year() }} · {{ $c->label() }} ({{ Istilah::cycleStatus($c->status) }})</option>
        @endforeach
      </optgroup>
    @endforeach
  </select>
  @if($canAssign)
  <select name="petugas">
    <option value="">Semua petugas</option>
    <option value="none" @selected($fPetugas === 'none')>— belum ditugaskan —</option>
    @foreach ($operators as $id => $nama)
    <option value="{{ $id }}" @selected((string) $fPetugas === (string) $id)>{{ $nama }}</option>
    @endforeach
  </select>
  @endif
  <input name="q" value="{{ $fQ }}" placeholder="Cari nama KRT…">
  <button type="submit">Terapkan</button>
  <a class="btn-ghost" href="{{ route('monitoring.samples') }}">Reset</a>
</form>

<form method="post" action="{{ route('monitoring.samples.bulk-assign') }}" id="bulk-assign">
@csrf
<div class="tablewrap tall">
<table class="tbl">
  <thead>
    <tr>
      @if($canAssign)<th style="width:24px"><input type="checkbox" data-check-all></th>@endif
      <th>Desa / Blok Sensus / Rumah Tangga</th><th>Periode</th><th>Nama Kepala Rumah Tangga</th><th>Petugas</th><th>Tahap</th><th></th>
    </tr>
  </thead>
  <tbody>
  @forelse ($items as $s)
    <tr>
      @if($canAssign)<td><input type="checkbox" name="ids[]" value="{{ $s->id }}"></td>@endif
      <td>
        <div>{{ $wilNames[$s->wilayah_code] ?? $s->wilayah_code }}</div>
        <span class="mono muted">{{ $s->wilayah_code }} · Blok Sensus {{ $s->no_bs }} · Rumah Tangga {{ $s->no_ruta }}</span>
      </td>
      <td class="small">{{ $cycleNames[$s->cycle_id] ?? '—' }}</td>
      <td>{{ $s->krt_name ?: '—' }}</td>
      <td>{{ $userNames[$s->petugas_id] ?? '—' }}</td>
      @php($t = Tahap::of($s, $docBySample[$s->id] ?? null))
      <td><span class="chip t-{{ $t }}">{{ Tahap::label($t) }}</span></td>
      <td class="right"><a class="btn-ghost" href="{{ route('monitoring.sample', $s) }}">Buka</a></td>
    </tr>
  @empty
    <tr><td colspan="{{ $canAssign ? 7 : 6 }}" class="muted">Tidak ada sampel yang cocok.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
</form>

@if($canAssign && $items->count())
<div class="row-form" style="margin-top:10px">
  <span class="muted small"><span data-check-count>0</span> dipilih</span>
  <select name="petugas_id" form="bulk-assign" required>
    <option value="">Tugaskan ke petugas…</option>
    @foreach ($operators as $id => $nama)<option value="{{ $id }}">{{ $nama }}</option>@endforeach
  </select>
  <button type="submit" form="bulk-assign">Tugaskan terpilih</button>
</div>
@endif

<p class="muted">{{ $items->count() }} baris.</p>

@if($canAssign)
<script>
(function () {
  var all = document.querySelector('[data-check-all]');
  var boxes = function () { return document.querySelectorAll('input[name="ids[]"]'); };
  var counter = document.querySelector('[data-check-count]');
  function refresh() {
    var n = 0; boxes().forEach(function (b) { if (b.checked) n++; });
    if (counter) counter.textContent = n;
  }
  if (all) all.addEventListener('change', function () {
    boxes().forEach(function (b) { b.checked = all.checked; }); refresh();
  });
  boxes().forEach(function (b) { b.addEventListener('change', refresh); });
})();
</script>
@endif
@endsection
