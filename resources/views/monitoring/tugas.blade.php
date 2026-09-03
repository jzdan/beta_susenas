@extends('layouts.app')
@php($nav = 'tugas')
@section('title', 'Tugas Saya · Sistem SUSENAS')
@section('content')
<h1>Tugas Saya</h1>
<p class="muted">Ringkasan hal yang perlu ditindaklanjuti dalam cakupan Anda.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif

<section class="panel">
  <h2>Dikembalikan untuk perbaikan <span class="chip d-dikembalikan">{{ $returned->count() }}</span></h2>
  @forelse ($returned as $r)
    <div class="task-row">
      <div>
        <a href="{{ route('entri.form', $r['doc']) }}"><strong>#{{ $r['doc']->id }}</strong> · {{ $r['wil'] }} · Ruta {{ $r['sample']->no_ruta }}</a>
        <span class="muted small">· {{ $r['doc']->returned_at?->format('d M Y') }}</span>
        <div class="small">{{ \Illuminate\Support\Str::limit($r['doc']->return_reason, 160) }}</div>
      </div>
      <a class="btn-ghost" href="{{ route('entri.form', $r['doc']) }}">Perbaiki →</a>
    </div>
  @empty
    <p class="muted">Tidak ada dokumen yang dikembalikan. 👍</p>
  @endforelse
</section>

<section class="panel">
  <h2>Catatan menunggu tanggapan <span class="chip">{{ $noteRows->count() }}</span></h2>
  @forelse ($noteRows as $n)
    <div class="task-row">
      <div>
        <a href="{{ route('entri.form', $n['doc_id']) }}"><strong>#{{ $n['doc_id'] }}</strong> · {{ $n['ruta'] }}</a>
        <span class="muted small">· {{ $n['note']->user->name ?? '—' }} · {{ $n['note']->created_at?->format('d M H:i') }}</span>
        <div class="small">{{ \Illuminate\Support\Str::limit($n['note']->body, 160) }}</div>
      </div>
      <a class="btn-ghost" href="{{ route('entri.form', $n['doc_id']) }}#catatan">Buka →</a>
    </div>
  @empty
    <p class="muted">Tidak ada catatan baru dari orang lain.</p>
  @endforelse
</section>

<section class="panel">
  <h2>Sampel belum tuntas <span class="chip s-proses">{{ $pending->count() }}</span></h2>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th>Wilayah / Ruta</th><th>Status</th><th>Dokumen</th><th></th></tr></thead>
    <tbody>
    @forelse ($pending as $p)
      <tr>
        <td>{{ $p['wil'] }} <span class="mono muted">· BS {{ $p['sample']->no_bs }} · {{ $p['sample']->no_ruta }}</span><br>
          <span class="muted small">{{ $p['sample']->krt_name }}</span></td>
        <td><span class="chip s-{{ $p['sample']->status }}">{{ $p['sample']->status }}</span></td>
        <td>@if($p['doc'])<span class="chip d-{{ $p['doc']->status }}">{{ $p['doc']->status }}</span>@else <span class="muted">belum ada</span>@endif</td>
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
    @empty
      <tr><td colspan="4" class="muted">Semua sampel sudah tuntas. 🎉</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>
@endsection
