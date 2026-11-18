@extends('layouts.app')
@php($nav = 'pengolahan')
@section('title', "Pengolahan dok #{$doc->id} · Sistem SUSENAS")
@section('content')
<p><a class="btn-ghost" href="{{ route('pengolahan.index') }}">← Antrean</a>
  <a class="btn-ghost" href="{{ route('entri.print', $doc) }}">Cetak / arsip</a>
  @if($nextDoc)<a class="btn-ghost" href="{{ route('pengolahan.show', $nextDoc) }}">Lewati ke #{{ $nextDoc->id }} →</a>@endif</p>
<h1>Pengolahan Sampel #{{ $doc->id }}</h1>
<p class="muted mono">{{ $sample->wilayah_code }} · Blok Sensus {{ $sample->no_bs }} · Rumah Tangga {{ $sample->no_ruta }}
  · {{ $sample->krt_name }} <span class="chip d-{{ $doc->status }}">{{ $doc->status }}</span>
  @if($doc->waitingDays() !== null)<span class="muted small">· menunggu {{ $doc->waitingDays() }} hari</span>@endif</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

@if($doc->status === 'dikembalikan')
<div class="alert err"><strong>Menunggu perbaikan petugas.</strong> Alasan dikembalikan:<br>{{ $doc->return_reason }}</div>
@endif

<div class="summary-counts" style="margin:12px 0">
  <span class="cnt err">{{ $nErr }}</span> error
  <span class="cnt warn">{{ $nWarn }}</span> warning
  @if($nWarnAck)<span class="muted small">({{ $nWarnAck }} sudah ditinjau, {{ max(0, $nWarn - $nWarnAck) }} belum)</span>@endif
  @if($doc->status !== 'bersih')
  <form method="post" action="{{ route('pengolahan.bersih', $doc) }}" style="margin-left:auto;display:flex;gap:6px">@csrf
    <button type="submit" @disabled($nErr > 0)>Tandai bersih</button>
    @if($nextDoc)<button type="submit" name="next" value="1" class="btn-ghost" @disabled($nErr > 0)>bersih &amp; lanjut →</button>@endif
  </form>
  @elseif(auth()->user()->role === 'admin')
  <form method="post" action="{{ route('pengolahan.reopen', $doc) }}" style="margin-left:auto">@csrf
    <button class="btn-ghost" type="submit">Buka kunci</button>
  </form>
  @endif
</div>

@if(!in_array($doc->status, ['bersih','dikembalikan']))
<details class="panel" style="padding:12px 16px">
  <summary style="cursor:pointer">Kembalikan sampel ke petugas (butuh kunjungan/perbaikan lapangan)</summary>
  <form method="post" action="{{ route('pengolahan.kembalikan', $doc) }}" class="stack-form mt">
    @csrf
    <label>Alasan &amp; instruksi perbaikan
      <textarea name="reason" rows="3" required minlength="5"
        placeholder="mis. Blok IV.1 beras kuantitas tidak wajar — konfirmasi ke responden dan perbaiki"></textarea>
    </label>
    <button type="submit" class="btn-ghost">Kembalikan ke petugas</button>
    <p class="muted small">Status sampel menjadi <em>dikembalikan</em> dan keluar dari antrean cleaning sampai petugas mengirim ulang.</p>
  </form>
</details>
@endif

@if($docWarn)
<div class="panel"><h3>Peringatan tingkat sampel</h3>
  <ul class="doc-flags">@foreach ($docWarn as $w)<li>@include('pengolahan._warnflag', ['w' => $w, 'var' => ''])</li>@endforeach</ul>
</div>
@endif

<p>
  <a class="btn-ghost" href="{{ route('pengolahan.show', [$doc, 'semua' => $onlyIssues ? 1 : 0]) }}">
    {{ $onlyIssues ? 'Tampilkan semua isian' : 'Hanya yang bermasalah/dikoreksi' }}
  </a>
</p>

<div class="tablewrap">
<table class="tbl">
  <thead><tr><th>Blok / Rincian</th><th>Nilai mentah</th><th>Nilai bersih</th><th>Alasan</th><th></th></tr></thead>
  <tbody>
  @forelse ($items as $it)
    <tr class="{{ $it['warnings'] ? 'row-has-warn' : '' }}">
      <td>
        <span class="mono muted">{{ $it['block'] }} · {{ $it['code'] }}</span>
        @if($it['row_label']) <span class="mono muted">[{{ $it['row_label'] }}]</span>@endif<br>
        {{ $it['label'] }}
        @foreach ($it['warnings'] as $w)@include('pengolahan._warnflag', ['w' => $w, 'var' => $it['var']])@endforeach
      </td>
      <td class="mono">{{ $it['raw'] === '' ? '—' : $it['raw'] }}</td>
      <td colspan="3">
        @if($doc->status === 'bersih')
          <span class="mono">{{ $it['clean'] ?? '(tidak diubah)' }}</span>
        @else
        <form method="post" action="{{ route('pengolahan.correct', $doc) }}" class="row-form">
          @csrf
          <input type="hidden" name="question_id" value="{{ $it['qid'] }}">
          <input type="hidden" name="art_index" value="{{ $it['art'] }}">
          <input name="clean_value" value="{{ $it['clean'] }}" placeholder="{{ $it['raw'] }}" class="mono" style="width:120px">
          <input name="reason" value="{{ $it['reason'] ? \Illuminate\Support\Str::after($it['reason'], 'CLEANING: ') : '' }}"
                 placeholder="alasan koreksi (wajib)" style="flex:1;min-width:160px">
          <button class="btn-ghost" type="submit">Simpan</button>
        </form>
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="5" class="muted">
      {{ $onlyIssues ? 'Tidak ada isian bermasalah atau yang sudah dikoreksi.' : 'Belum ada isian.' }}
    </td></tr>
  @endforelse
  </tbody>
</table>
</div>

<div class="panel mt">
  @include('_catatan', ['from' => 'pengolahan'])
</div>
@endsection
