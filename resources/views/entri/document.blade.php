@extends('layouts.app')
@php($nav = 'sampel')
@section('title', "Entri dokumen #{$doc->id} · Sistem SUSENAS")
@section('content')
<p><a class="btn-ghost" href="{{ route('monitoring.sample', $sample) }}">← Sampel</a>
  <a class="btn-ghost" href="{{ route('entri.print', $doc) }}">Cetak / arsip</a></p>
<h1>Entri Kuesioner</h1>
<p class="muted mono">
  {{ $qv->code }} · {{ $wil[$sample->wilayah_code] ?? $sample->wilayah_code }} ·
  BS {{ $sample->no_bs }} · Ruta {{ $sample->no_ruta }} · dok #{{ $doc->id }}
  <span class="chip d-{{ $doc->status }}">{{ $doc->status }}</span>
</p>

@if ($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

@if ($doc->status === 'dikembalikan')
<div class="alert err">
  <strong>Dokumen dikembalikan untuk perbaikan</strong>
  @if($doc->returned_at)<span class="muted small">· {{ $doc->returned_at->format('d M Y H:i') }}</span>@endif
  <br>{{ $doc->return_reason }}
  <br><span class="muted small">Perbaiki isian yang dimaksud, lalu tekan "Kirim ulang" di panel ringkasan.</span>
</div>
@endif

<div class="entry-layout">
  <div class="entry-main" data-doc="{{ $doc->id }}" data-can-edit="{{ $canEdit ? '1' : '0' }}">
    @foreach ($state['blocks'] as $b)
    <section class="panel block" data-block="{{ $b['code'] }}" id="blok-{{ $b['code'] }}">
      <h2 class="block-head" data-block-toggle>
        <span class="block-caret" aria-hidden="true">▾</span>
        {{ $b['code'] }} — {{ $b['title'] }}
        @if($b['repeat'] === 'art')<span class="chip d-draft">roster per ART</span>@endif
        @if($b['repeat'] === 'list')<span class="chip d-draft">daftar (baris tetap)</span>@endif
        @if($b['repeat'] === 'list_art')<span class="chip d-draft">daftar × per ART</span>@endif
        @if($b['n_err'])<span class="flag error" style="display:inline-block">{{ $b['n_err'] }} error</span>@endif
        @if($b['n_warn'])<span class="flag warning" style="display:inline-block">{{ $b['n_warn'] }} warning</span>@endif
      </h2>
      <div class="block-body">

      @if(in_array($b['repeat'], ['art','list','list_art']))
      <div class="roster-scroll">
      <table class="roster">
        <thead>
          <tr>
            <th>{{ $b['repeat'] === 'list' ? 'Rincian' : ($b['repeat'] === 'list_art' ? 'Komoditas / ART' : 'ART') }}</th>
            @foreach ($b['columns'] as $c)
            <th data-var="{{ $c['var'] }}">
              <span class="mono">{{ $c['code'] }}</span> {{ $c['label'] }}
              <div class="col-flags">
                @foreach ($c['warnings'] as $w)<span class="flag {{ $w['status'] }}">{{ $w['message'] }}</span>@endforeach
              </div>
            </th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @foreach ($b['rows'] as $row)
          @if(isset($row['group_header']))
          <tr class="group-header"><th colspan="{{ count($b['columns']) + 1 }}">{{ $row['group_header'] }}</th></tr>
          @continue
          @endif
          <tr @class(['row-has-warn' => !empty($row['warnings'])])>
            <th class="{{ $row['label'] ? 'row-label' : '' }}">{{ $row['label'] ?? $row['idx'] }}</th>
            @foreach ($row['cells'] as $ci => $cell)
            @php($col = $b['columns'][$ci])
            <td class="rcell @if(!empty($cell['warnings'])) has-{{ $cell['warnings'][0]['status'] }} @endif"
                data-qid="{{ $cell['qid'] }}" data-art="{{ $row['idx'] }}" data-var="{{ $cell['var'] }}">
              @if(!empty($col['options']))
              <select class="answer" @disabled(!$canEdit)>
                <option value="">—</option>
                @foreach ($col['options'] as $o)
                <option value="{{ $o['code'] }}" @selected($cell['value'] === $o['code'])>{{ $o['code'] }} · {{ $o['label'] }}</option>
                @endforeach
              </select>
              @else
              <input class="answer" value="{{ $cell['value'] }}" @disabled(!$canEdit)>
              @endif
            </td>
            @endforeach
          </tr>
          @if(!empty($row['warnings']))
          <tr class="row-warn"><td colspan="{{ count($b['columns']) + 1 }}">
            @foreach ($row['warnings'] as $w)<span class="flag {{ $w['status'] }}">{{ $row['label'] ?? ('Baris '.$row['idx']) }}: {{ $w['message'] }}</span>@endforeach
          </td></tr>
          @endif
          @endforeach
        </tbody>
      </table>
      </div>
      @if(in_array($b["repeat"], ["art","list_art"]))<p class="muted small">Jumlah baris mengikuti Banyaknya ART (R301). Ubah R301 lalu klik <em>Terapkan jumlah ART</em> di Blok POKOK — baris bertambah / berkurang otomatis (isian ART yang dihapus tak bisa dikembalikan).</p>@endif

      @else
      @foreach ($b['questions'] as $q)
      <div class="field {{ $q['skipped'] ? 'is-skipped' : '' }}" data-var="{{ $q['var'] }}" data-qid="{{ $q['id'] }}">
        <label>
          <span class="q-code mono">{{ $q['code'] }}</span>
          <span class="q-label">{{ $q['label'] }}</span>
          @if($q['unit'])<span class="q-unit">{{ $q['unit'] }}</span>@endif
        </label>
        @if(!empty($q['options']))
          <select class="answer" @disabled($q['skipped'] || !$canEdit)>
            <option value="">—</option>
            @foreach ($q['options'] as $o)
            <option value="{{ $o['code'] }}" @selected($q['value'] === $o['code'])>{{ $o['code'] }} · {{ $o['label'] }}</option>
            @endforeach
          </select>
        @else
          <input class="answer" inputmode="decimal" value="{{ $q['value'] }}"
                 @disabled($q['skipped'] || !$canEdit)
                 placeholder="{{ $q['skipped'] ? 'tidak berlaku' : '0' }}">
        @endif
        @if(($state['art_var'] ?? null) === $q['var'] && $canEdit)
          <button type="button" id="apply-art" class="btn-ghost" style="margin-top:6px">Terapkan jumlah ART</button>
          <span class="muted small" id="apply-art-note">tabel roster &amp; makanan-jadi disesuaikan ke angka ini</span>
        @endif
        @if($q['help'])<div class="q-help">{{ $q['help'] }}</div>@endif
        <div class="q-flags">
          @foreach ($q['warnings'] as $w)<span class="flag {{ $w['status'] }}">{{ $w['message'] }}</span>@endforeach
        </div>
      </div>
      @endforeach
      @endif
      </div>
    </section>
    @endforeach
  </div>

  <aside class="entry-side">
    <div class="panel sticky">
      <h2>Ringkasan</h2>
      <div class="summary-counts">
        <span class="cnt err" id="cnt-err">{{ $state['n_err'] }}</span> error
        <span class="cnt warn" id="cnt-warn">{{ $state['n_warn'] }}</span> warning
        <span class="saved-note" id="saved-note"></span>
      </div>
      @if($canEdit)
        <button type="button" id="next-issue" class="btn-ghost" style="margin-top:6px">↧ isu berikutnya</button>
      @endif

      @php($pr = $state['progress'])
      <div class="entry-progress" id="entry-progress">
        <div class="prog-label">Kelengkapan entri
          <span><strong data-prog-pct>{{ $pr['pct'] }}</strong>% ·
            <span data-prog-filled>{{ $pr['filled'] }}</span>/<span data-prog-total>{{ $pr['expected'] }}</span></span>
        </div>
        <div class="prog-bar"><span data-prog-fill style="width:{{ $pr['pct'] }}%"></span></div>
        <details class="prog-missing" @if($pr['missing_total'] === 0) hidden @endif>
          <summary>Belum diisi (<span data-prog-missing-n>{{ $pr['missing_total'] }}</span>)</summary>
          <ul data-prog-missing-list>
            @foreach ($pr['missing'] as $m)
            <li><a href="#blok-{{ $m['block'] }}"><span class="mono">{{ $m['code'] }}</span> {{ $m['label'] }}</a></li>
            @endforeach
          </ul>
          @if($pr['missing_total'] > count($pr['missing']))<p class="muted small">…dan {{ $pr['missing_total'] - count($pr['missing']) }} lagi.</p>@endif
        </details>
      </div>

      <h3>Navigasi blok</h3>
      <ul class="block-nav">
        @foreach ($state['blocks'] as $b)
        <li>
          <a href="#blok-{{ $b['code'] }}" data-nav-block="{{ $b['code'] }}">{{ $b['code'] }} — {{ $b['title'] }}</a>
          @if($b['n_err'])<span class="pill err">{{ $b['n_err'] }}</span>@endif
          @if($b['n_warn'])<span class="pill warn">{{ $b['n_warn'] }}</span>@endif
        </li>
        @endforeach
      </ul>

      <h3>Peringatan tingkat dokumen</h3>
      <ul class="doc-flags" id="doc-flags">
        @forelse ($state['doc_level'] as $w)
        <li class="flag {{ $w['status'] }}">{{ $w['message'] }}</li>
        @empty
        <li class="muted">Tidak ada.</li>
        @endforelse
      </ul>

      <h3>Nilai turunan</h3>
      <table class="tbl mini" id="derived">
        @foreach ($state['derived'] as $d)
        <tr><td>{{ $d['label'] }}</td><td class="num" data-dvar="{{ $d['var'] }}">{{ $d['value'] ?? '—' }}</td></tr>
        @endforeach
      </table>

      @if(!empty($state['rekap']))
      <h3>Rekap per kelompok</h3>
      <table class="tbl mini" id="rekap">
        @foreach ($state['rekap'] as $r)
        <tr><td>{{ $r['label'] }}</td><td class="num" data-dvar="{{ $r['var'] }}">{{ $r['value'] ?? '—' }}</td></tr>
        @endforeach
      </table>
      @endif

      @if(in_array(auth()->user()->role, ['operator','pml','admin']) && $doc->status !== 'bersih')
      <form method="post" action="{{ route('entri.finalize', $doc) }}" class="mt">
        @csrf
        <button type="submit" @disabled($state['n_err'] > 0)>
          {{ $doc->status === 'dikembalikan' ? 'Kirim ulang ke pemeriksa' : 'Finalisasi (tandai selesai)' }}
        </button>
      </form>
      @if($state['n_err'] > 0)<p class="muted small">Perbaiki semua error dulu.</p>@endif
      @endif
    </div>

    <div class="panel">
      @include('_catatan', ['from' => 'entri'])
    </div>

    @include('entri._bukti_kunjungan')
  </aside>
</div>
@endsection
