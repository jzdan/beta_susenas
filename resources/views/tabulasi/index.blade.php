@extends('layouts.app')
@php($nav = 'tabulasi')
@section('title', 'Tabulasi & Ekspor · Sistem SUSENAS')
@section('content')
<h1>Tabulasi &amp; Ekspor</h1>
<p><a class="btn-ghost" href="{{ route('tabulasi.indikator') }}">Indikator kesejahteraan →</a></p>
<p class="muted">Rekap internal untuk pra-pengolahan &amp; pemeriksaan mutu — <strong>bukan estimasi resmi</strong>.
  Agregasi tertimbang memakai <em>design weight</em> per sampel (belum dikalibrasi/di-<em>raking</em>),
  jadi hanya untuk pemantauan arah angka. {{ $totalDocs }} sampel dalam cakupan Anda.</p>

<div class="grid2">
  <section class="panel">
    <h2>Ekspor</h2>
    <p class="muted small">Data datar = satu baris per sampel, kolom = seluruh variabel
      (jawaban + turunan + agregat). Peringatan = satu baris per temuan aturan.
      Koreksi = jejak setiap perubahan nilai saat pengolahan (nilai lama → baru, alasan, petugas).</p>
    <div class="row-form">
      <a class="btn-ghost" href="{{ route('tabulasi.export.raw', ['selesai' => $onlySelesai ? 1 : 0]) }}">⬇ Data datar (semua kolom)</a>
      <a class="btn-ghost" href="{{ route('tabulasi.export.rules') }}">⬇ Daftar peringatan (CSV)</a>
      <a class="btn-ghost" href="{{ route('tabulasi.export.corrections') }}">⬇ Jejak koreksi (CSV)</a>
    </div>

    <details style="margin-top:8px">
      <summary style="cursor:pointer">Data datar — pilih kolom ({{ count($vars) }} variabel)</summary>
      <form method="get" action="{{ route('tabulasi.export.raw') }}" class="mt">
        <input type="hidden" name="selesai" value="{{ $onlySelesai ? 1 : 0 }}">
        <p class="row-form">
          <button type="button" class="btn-ghost" onclick="this.closest('form').querySelectorAll('.colpick').forEach(c=>c.checked=true)">semua</button>
          <button type="button" class="btn-ghost" onclick="this.closest('form').querySelectorAll('.colpick').forEach(c=>c.checked=false)">kosongkan</button>
        </p>
        <div style="max-height:220px;overflow:auto;border:1px solid var(--line);border-radius:var(--radius);padding:6px">
          @foreach ($vars as $v)
          <label class="small" style="display:inline-block;width:calc(50% - 8px);margin:1px 0">
            <input type="checkbox" class="colpick" name="cols[]" value="{{ $v }}"> <span class="mono">{{ $v }}</span>
          </label>
          @endforeach
        </div>
        <button type="submit" class="mt">⬇ Unduh kolom terpilih</button>
      </form>
    </details>
  </section>

  <section class="panel">
    <h2>Cakupan</h2>
    <form method="get" class="row-form">
      <input type="hidden" name="var" value="{{ $var }}">
      <input type="hidden" name="group" value="{{ $group }}">
      <input type="hidden" name="agg" value="{{ $agg }}">
      <label><input type="checkbox" name="selesai" value="1" @checked($onlySelesai) onchange="this.form.submit()">
        hanya sampel berstatus selesai/bersih</label>
    </form>
  </section>
</div>

<section class="panel">
  <h2>Tabulasi</h2>
  <form method="get" class="row-form">
    <input type="hidden" name="selesai" value="{{ $onlySelesai ? 1 : 0 }}">
    <label>Variabel
      <select name="var">
        @foreach ($vars as $v)<option value="{{ $v }}" @selected($v === $var)>{{ $v }}</option>@endforeach
      </select>
    </label>
    <label>Baris
      <select name="group">
        @foreach ($groups as $k => $lbl)<option value="{{ $k }}" @selected($k === $group)>{{ $lbl }}</option>@endforeach
      </select>
    </label>
    <label>Kolom (silang, opsional)
      <select name="group2">
        <option value="">—</option>
        @foreach ($groups as $k => $lbl)
          @continue($k === '')
          <option value="{{ $k }}" @selected($k === $group2)>{{ $lbl }}</option>
        @endforeach
      </select>
    </label>
    <label>Agregasi
      <select name="agg">
        @foreach ($aggs as $k => $lbl)<option value="{{ $k }}" @selected($k === $agg)>{{ $lbl }}</option>@endforeach
      </select>
    </label>
    <button type="submit">Hitung</button>
    <a class="btn-ghost" href="{{ route('tabulasi.index', array_merge(request()->query(), ['export' => 1])) }}">⬇ CSV</a>
  </form>

  @if($weighted && $missingWeight > 0)
    <p class="alert warn small">{{ $missingWeight }} sampel dikecualikan dari agregasi tertimbang karena penimbang kosong/0.</p>
  @endif

  @if(empty($result))
    <p class="muted">Tidak ada data untuk variabel ini.@if($weighted) Pastikan sampel punya penimbang.@endif</p>
  @elseif($matrix)
    <div class="roster-scroll">
    <table class="tbl">
      <thead><tr><th>{{ $groups[$group] }} \ {{ $groups[$group2] }}</th>
        @foreach ($colKeys as $ck)<th class="num">{{ $ck }}</th>@endforeach
      </tr></thead>
      <tbody>
      @foreach ($matrix as $line)
        <tr>
          <td>{{ $line['key'] }}</td>
          @foreach ($line['cells'] as $c)
          <td class="num" title="n={{ $c['n'] }}">{{ is_null($c['val']) ? '·' :
            number_format((float) $c['val'], in_array($agg, ['mean','wmean']) ? 2 : 0, ',', '.') }}</td>
          @endforeach
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
    <p class="muted small">Sel = {{ $aggs[$agg] }} variabel <span class="mono">{{ $var }}</span>. Arahkan kursor untuk melihat n.</p>
  @elseif($agg === 'dist')
    <div class="tablewrap">
    <table class="tbl">
      <thead><tr><th>Kelompok</th><th class="num">n</th><th>Distribusi nilai</th></tr></thead>
      <tbody>
      @foreach ($result as $r)
        <tr>
          <td>{{ $r['key'] }}</td>
          <td class="num">{{ $r['n'] }}</td>
          <td>
            @foreach ($r['dist'] as $val => $cnt)
              <span class="chip">{{ $val === '' ? '(kosong)' : $val }}: {{ $cnt }}</span>
            @endforeach
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  @else
    <div class="tablewrap">
    <table class="tbl">
      <thead><tr><th>Kelompok</th><th class="num">n sampel</th><th class="num">{{ $aggs[$agg] }}</th></tr></thead>
      <tbody>
      @foreach ($result as $r)
        <tr>
          <td>{{ $r['key'] }}</td>
          <td class="num">{{ $r['n'] }}</td>
          <td class="num">{{ is_null($r['val']) ? '—' : number_format((float) $r['val'], in_array($agg, ['mean','wmean']) ? 2 : 0, ',', '.') }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  @endif
</section>
@endsection
