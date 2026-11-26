@extends('layouts.app')
@php($nav = 'tugas')
@section('title', 'Tugas Saya · Sistem SUSENAS')
@section('content')
<h1>Tugas Saya</h1>
<p class="muted">Ringkasan hal yang perlu ditindaklanjuti dalam cakupan Anda.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif

{{-- Inc 162: simpan halaman entri ke HP sebelum berangkat ke lokasi minim sinyal --}}
@if($isOperator)
@php($offlineUrls = $pending->pluck('doc')->merge($returned->pluck('doc'))->filter()->unique('id')
    ->map(fn ($d) => route('entri.form', $d, false))->values()->prepend(route('monitoring.tasks', [], false)))
<section class="panel offline-prep">
  <div style="flex:1;min-width:220px">
    <strong>Mau ke lokasi yang sinyalnya lemah?</strong>
    <div class="small muted">Simpan dulu {{ $offlineUrls->count() - 1 }} sampel yang sudah pernah dibuka ke HP ini, supaya tetap bisa dibuka dan diisi tanpa sinyal.
      Isian dikirim otomatis begitu sinyal kembali. Sampel yang belum pernah dibuka perlu dibuka sekali saat ada sinyal.</div>
    <div class="small" id="prep-offline-out" aria-live="polite"></div>
  </div>
  <button type="button" id="prep-offline" data-urls="{{ $offlineUrls->toJson() }}">Simpan untuk dibuka tanpa sinyal</button>
</section>
@endif

@if($returned->isEmpty() && $noteRows->isEmpty() && $pending->isEmpty())
<section class="panel"><p class="muted">Tidak ada yang perlu ditindaklanjuti saat ini.</p></section>
@endif

@if($returned->isNotEmpty())
<section class="panel">
  <h2>Dikembalikan untuk perbaikan ({{ $returned->count() }})</h2>
  @forelse ($returned->groupBy('siklus') as $siklusName => $rows)
    <details class="rule-group" open>
      <summary style="cursor:pointer;font-weight:600">{{ $siklusName }} <span class="muted small">({{ $rows->count() }})</span></summary>
      @foreach ($rows as $r)
        <div class="task-row">
          <div>
            <a href="{{ route('entri.form', $r['doc']) }}"><strong>#{{ $r['doc']->id }}</strong> · {{ $r['wil'] }} · Rumah Tangga {{ $r['sample']->no_ruta }}</a>
            <span class="muted small">· {{ $r['doc']->returned_at?->format('d M Y') }}</span>
            <div class="small">{{ \Illuminate\Support\Str::limit($r['doc']->return_reason, 160) }}</div>
          </div>
          <a class="btn-ghost" href="{{ route('entri.form', $r['doc']) }}">Perbaiki →</a>
        </div>
      @endforeach
    </details>
  @empty
  @endforelse
</section>
@endif

@if($noteRows->isNotEmpty())
<section class="panel">
  <h2>Catatan menunggu tanggapan ({{ $noteRows->count() }})</h2>
  @forelse ($noteRows as $n)
    <div class="task-row">
      <div>
        <a href="{{ route('entri.form', $n['doc_id']) }}"><strong>#{{ $n['doc_id'] }}</strong> · {{ $n['ruta'] }}</a>
        <span class="muted small">· {{ $n['siklus'] }}</span>
        <span class="muted small">· {{ $n['note']->user->name ?? '—' }} · {{ $n['note']->created_at?->format('d M H:i') }}</span>
        <div class="small">{{ \Illuminate\Support\Str::limit($n['note']->body, 160) }}</div>
      </div>
      <a class="btn-ghost" href="{{ route('entri.form', $n['doc_id']) }}#catatan">Buka →</a>
    </div>
  @empty
  @endforelse
</section>
@endif

@if($pending->isNotEmpty())
<section class="panel">
  <h2>Sampel belum tuntas ({{ $pending->count() }})</h2>
  @forelse ($pending->groupBy('siklus') as $siklusName => $rows)
    <details class="rule-group" open>
      <summary style="cursor:pointer;font-weight:600">{{ $siklusName }} <span class="muted small">({{ $rows->count() }} sampel)</span></summary>
      <div class="tablewrap">
      <table class="tbl">
        <thead><tr><th>Wilayah / Rumah Tangga</th><th>Tahap</th><th></th></tr></thead>
        <tbody>
        @foreach ($rows as $p)
          <tr>
            <td>{{ $p['wil'] }} <span class="mono muted">· Blok Sensus {{ $p['sample']->no_bs }} · Rumah Tangga {{ $p['sample']->no_ruta }}</span><br>
              <span class="muted small">{{ $p['sample']->krt_name }}</span></td>
            @php($t = Tahap::of($p['sample'], $p['doc']))
            <td><span class="chip t-{{ $t }}">{{ Tahap::label($t) }}</span></td>
            <td class="right">
              @if($p['doc'])
                <a class="btn-ghost" href="{{ route('entri.form', $p['doc']) }}">Lanjut entri →</a>
              @elseif($isOperator)
                <form method="post" action="{{ route('monitoring.sample.document', $p['sample']) }}">@csrf
                  <button type="submit" class="btn-ghost">Mulai entri →</button>
                </form>
              @else
                <a class="btn-ghost" href="{{ route('monitoring.sample', $p['sample']) }}">Buka sampel →</a>
              @endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
      </div>
    </details>
  @empty
  @endforelse
</section>
@endif
@endsection
