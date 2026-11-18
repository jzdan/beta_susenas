@extends('layouts.app')
@php($nav = 'sampel')
@section('title', "Sampel {$s->no_ruta} · Sistem SUSENAS")
@section('content')
<p><a class="btn-ghost" href="{{ route('monitoring.samples') }}">← Daftar sampel</a></p>
<h1>{{ $s->krt_name ?: 'Rumah Tangga '.$s->no_ruta }} <span class="chip">{{ $s->cycle->name ?? '—' }}</span></h1>
<p class="muted mono">{{ $wilNames[$s->wilayah_code] ?? $s->wilayah_code }} ·
  {{ $s->wilayah_code }} · Blok Sensus {{ $s->no_bs }} · Rumah Tangga {{ $s->no_ruta }}</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<div class="grid2">
  <section class="panel">
    <h2>Keterangan</h2>
    <dl class="kv">
      <div><dt>Pencacah Lapangan</dt><dd>{{ $userNames[$s->petugas_id] ?? '—' }}</dd></div>
      <div><dt>Pengawas Lapangan</dt><dd>{{ $userNames[$s->pml_id] ?? '—' }}</dd></div>
      <div><dt>Status</dt><dd><span class="chip s-{{ $s->status }}">{{ $statusLabels[$s->status] ?? $s->status }}</span></dd></div>
      @if($s->nonresponse_reason)
      <div><dt>Alasan</dt><dd>{{ $nrReasons[$s->nonresponse_reason] ?? $s->nonresponse_reason }}
        @if($s->status_note)<br><span class="muted small">{{ $s->status_note }}</span>@endif</dd></div>
      @endif
      @if($original)
      <div><dt>Pengganti dari</dt><dd><a href="{{ route('monitoring.sample', $original) }}">Rumah Tangga {{ $original->no_ruta }} · {{ $original->krt_name ?: '—' }}</a></dd></div>
      @endif
      @if($replacement)
      <div><dt>Digantikan oleh</dt><dd><a href="{{ route('monitoring.sample', $replacement) }}">Rumah Tangga {{ $replacement->no_ruta }} · {{ $replacement->krt_name ?: '—' }}</a>
        <span class="chip s-{{ $replacement->status }}">{{ $statusLabels[$replacement->status] ?? $replacement->status }}</span></dd></div>
      @endif
      <div><dt>Diperbarui</dt><dd>{{ $s->updated_at?->format('d-m-Y H:i') ?? '—' }}</dd></div>
    </dl>

    @if($canAssign)
    <form method="post" action="{{ route('monitoring.sample.status', $s) }}" class="row-form">
      @csrf
      <select name="new_status">
        @foreach ($statusLabels as $k => $lbl)
        <option value="{{ $k }}" @selected($s->status === $k)>{{ $lbl }}</option>
        @endforeach
      </select>
      <select name="reason">
        <option value="">— alasan (untuk non-respons / ganti) —</option>
        @foreach ($nrReasons as $k => $lbl)
        <option value="{{ $k }}" @selected($s->nonresponse_reason === $k)>{{ $lbl }}</option>
        @endforeach
      </select>
      <input name="note" value="{{ $s->status_note }}" placeholder="catatan (opsional)" style="flex:1;min-width:160px">
      <button type="submit">Ubah status</button>
    </form>

    @if(!$s->replaces_id && !$replacement && in_array($s->status, ['non_respons','ganti_sampel','proses','belum']))
    <details class="panel" style="padding:10px 14px;margin-top:10px">
      <summary style="cursor:pointer">Buat sampel pengganti</summary>
      <form method="post" action="{{ route('monitoring.sample.replace', $s) }}" class="stack-form mt">
        @csrf
        <label>Alasan penggantian
          <select name="reason" required>
            @foreach ($nrReasons as $k => $lbl)<option value="{{ $k }}" @selected($s->nonresponse_reason === $k)>{{ $lbl }}</option>@endforeach
          </select>
        </label>
        <label>Catatan <input name="note" value="{{ $s->status_note }}"></label>
        <div class="row-form">
          <label class="small">No. ruta pengganti <input name="no_ruta" placeholder="{{ $s->no_ruta }}P"></label>
          <label class="small">Nama Kepala Rumah Tangga (bila sudah tahu) <input name="krt_name"></label>
        </div>
        <button type="submit" class="btn-ghost">Buat pengganti &amp; tandai ganti_sampel</button>
      </form>
    </details>
    @endif

    <form method="post" action="{{ route('monitoring.sample.assign', $s) }}" class="row-form" style="margin-top:10px">
      @csrf
      <label class="small">Petugas
        <select name="petugas_id">
          <option value="">—</option>
          @foreach ($assignable['operator'] as $id => $nama)
          <option value="{{ $id }}" @selected($s->petugas_id === $id)>{{ $nama }}</option>
          @endforeach
        </select>
      </label>
      <label class="small">Pengawas
        <select name="pml_id">
          <option value="">—</option>
          @foreach ($assignable['pml'] as $id => $nama)
          <option value="{{ $id }}" @selected($s->pml_id === $id)>{{ $nama }}</option>
          @endforeach
        </select>
      </label>
      <button type="submit">Simpan penugasan</button>
    </form>
    @endif
  </section>

  <section class="panel">
    <h2>Entri sampel</h2>
    @forelse ($docs as $d)
      <div class="doc-row">
        <span class="chip d-{{ $d->status }}">{{ $d->status }}</span>
        <span class="muted">dibuat {{ $d->created_at?->format('d-m-Y') }}</span>
        <a class="btn-ghost" href="{{ route('entri.form', $d) }}">Buka entri →</a>
      </div>
    @empty
      <p class="muted">Belum ada isian — sampel ini belum diisi petugas.</p>
      @if(in_array(auth()->user()->role, ['operator','admin']))
      <form method="post" action="{{ route('monitoring.sample.document', $s) }}">
        @csrf
        <button type="submit">Mulai entri</button>
      </form>
      @endif
    @endforelse
  </section>
</div>

@if(config('susenas.bukti_kunjungan'))
<section class="panel">
  <h2>Bukti kunjungan ({{ $visitPhotos->count() }})</h2>
  @if($visitPhotos->isEmpty())
    <p class="muted small">Belum ada foto diunggah untuk rumah tangga ini.</p>
  @else
    <div class="foto-grid">
      @foreach ($visitPhotos as $ph)
        <figure class="foto-item">
          <a href="{{ route('kunjungan.foto', $ph) }}" target="_blank" rel="noopener">
            <img src="{{ route('kunjungan.foto', $ph) }}" alt="{{ $ph->caption ?: 'bukti kunjungan' }}" loading="lazy">
          </a>
          <figcaption>{{ $ph->caption ?: '—' }}
            <span class="muted">{{ $ph->uploader?->name }} · {{ $ph->created_at->format('d/m/Y H:i') }}</span>
          </figcaption>
        </figure>
      @endforeach
    </div>
  @endif
</section>
@endif

@if($audit->isNotEmpty())
<section class="panel">
  <h2>Riwayat</h2>
  <div class="tablewrap">
  <table class="tbl"><tbody>
    @foreach ($audit as $a)
      <tr><td class="mono muted">{{ $a->at?->format('d-m H:i') }}</td>
          <td>{{ $a->action }}</td><td>{{ $a->detail }}</td>
          <td>{{ $userNames[$a->by_user] ?? '—' }}</td></tr>
    @endforeach
  </tbody></table>
  </div>
</section>
@endif
@endsection
