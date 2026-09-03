@extends('layouts.app')
@section('title', 'Notifikasi · Sistem SUSENAS')
@section('content')
<h1>Notifikasi</h1>

@if($notifs->whereNull('read_at')->count())
<form method="post" action="{{ route('notifikasi.read-all') }}" class="mt">
  @csrf
  <button type="submit" class="btn-ghost">Tandai semua terbaca</button>
</form>
@endif

<table class="tbl mt">
  <tbody>
  @forelse ($notifs as $n)
    <tr @class(['row-has-warn' => is_null($n->read_at)])>
      <td style="width:1%">@if(is_null($n->read_at))<span class="pill err">baru</span>@endif</td>
      <td>
        <a href="{{ route('notifikasi.open', $n->id) }}"><strong>{{ $n->data['title'] ?? 'Notifikasi' }}</strong></a>
        <div class="small">{{ $n->data['body'] ?? '' }}</div>
        <div class="muted small mono">{{ $n->data['ref'] ?? '' }}
          @if($n->data['by'] ?? null) · oleh {{ $n->data['by'] }}@endif
          · {{ $n->created_at?->diffForHumans() }}</div>
      </td>
    </tr>
  @empty
    <tr><td class="muted">Belum ada notifikasi.</td></tr>
  @endforelse
  </tbody>
</table>
@endsection
