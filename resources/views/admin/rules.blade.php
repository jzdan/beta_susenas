@extends('layouts.app')
@php($nav = 'aturan')
@section('title', 'Aturan Validasi · Sistem SUSENAS')
@section('content')
@if($qv->cycle)
@include('periode._hub_header', \App\Http\Controllers\PeriodeController::hubContext($qv->cycle) + ['hubTab' => 'aturan'])
@else
<h1>Aturan Validasi</h1>
@endif
@if($tab === 'aturan')
<p class="muted">Pemeriksaan yang berjalan tiap kali isian disimpan. <strong>{{ $activeCount }}</strong> dari {{ $total }} aturan menyala.
  Sehari-hari cukup menyalakan/mematikan aturan dan mengubah ambang.</p>
@elseif($tab === 'neraca')
<p class="muted">Bahan hitungan: agregat (neraca), turunan, dan parameter. Semuanya dihitung lebih dulu,
  lalu dipakai aturan dan tabulasi; tab ini sendiri tidak memunculkan kesalahan atau peringatan.</p>
@else
<p class="muted">Patokan harga wajar dan kalori per komoditas, dipakai aturan harga dan kalori.</p>
@endif

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if ($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

@unless($qv->cycle)
@include('admin.partials._qv_switch')
@endunless
@include('admin.partials._ro_version', ['roQv' => $qv])

@include('admin.partials._tasks', ['tasks' => $tasks])
@php($recheckRunning = $tasks->contains(fn ($t) => $t->running()))
@if($nStale > 0 && !$recheckRunning)
<div class="alert stale-box">
  <div><strong>{{ $nStale }} dari {{ $nDocs }} sampel belum diperiksa ulang</strong> sejak aturan, neraca, atau patokan harga
    terakhir diubah. Hasil di Pengolahan dan Dashboard masih memakai aturan lama.</div>
  <form method="post" action="{{ route('admin.aturan.periksa-ulang') }}">
    @csrf<input type="hidden" name="qv" value="{{ $qv->id }}"><input type="hidden" name="tab" value="{{ $tab }}">
    <button type="submit">Periksa ulang sekarang</button>
  </form>
</div>
@endif

<nav class="row-form" style="margin:0 0 14px;gap:8px">
  <a class="btn-ghost" href="{{ route('admin.aturan', ['qv' => $qv->id, 'tab' => 'aturan', 'blok' => $fBlok ?: null]) }}"
     style="{{ $tab === 'aturan' ? 'font-weight:700;border-color:var(--acc)' : '' }}">Aturan ({{ $total }})</a>
  <a class="btn-ghost" href="{{ route('admin.aturan', ['qv' => $qv->id, 'tab' => 'neraca', 'blok' => $fBlok ?: null]) }}"
     style="{{ $tab === 'neraca' ? 'font-weight:700;border-color:var(--acc)' : '' }}"
     title="Agregat (neraca), variabel turunan, dan parameter: angka yang dihitung lebih dulu lalu dipakai aturan & tabulasi">Bahan hitungan <small class="muted">agregat · turunan · parameter</small></a>
  <a class="btn-ghost" href="{{ route('admin.aturan', ['qv' => $qv->id, 'tab' => 'harga', 'blok' => $fBlok ?: null]) }}"
     style="{{ $tab === 'harga' ? 'font-weight:700;border-color:var(--acc)' : '' }}">Harga &amp; Kalori</a>
</nav>

@if($fBlok !== '' && $tab !== 'harga')
<div class="alert blok-filter">Hanya menampilkan {{ $tab === 'neraca' ? 'agregat & turunan' : 'aturan' }} yang terhubung ke
  <strong>Blok {{ $fBlok }}</strong>@if($blockCodes[$fBlok] ?? null) ({{ $blockCodes[$fBlok] }})@endif.
  <a href="{{ route('admin.aturan', ['qv' => $qv->id, 'tab' => $tab]) }}">Tampilkan semua</a></div>
@endif

@if($tab === 'neraca')
  @include('admin.partials._neraca')
@elseif($tab === 'harga')
  @include('admin.partials._harga')
@else

<section class="panel">
  <form method="get" class="filters">
    <input type="hidden" name="qv" value="{{ $qv->id }}">
    <select name="sev">
      <option value="">semua tingkat</option>
      <option value="error" @selected($filters['sev'] === 'error')>Kesalahan (menahan tanda bersih)</option>
      <option value="warning" @selected($filters['sev'] === 'warning')>Peringatan</option>
    </select>
    <select name="active">
      <option value="">aktif &amp; nonaktif</option>
      <option value="ya" @selected($filters['active'] === 'ya')>aktif saja</option>
      <option value="tidak" @selected($filters['active'] === 'tidak')>nonaktif saja</option>
    </select>
    <select name="blok">
      <option value="">semua blok</option>
      @foreach ($blockCodes as $code => $title)
        <option value="{{ $code }}" @selected($fBlok === $code)>Blok {{ $code }} ({{ $links['byBlock'][$code]['rules'] ?? 0 }})</option>
      @endforeach
    </select>
    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="cari kode / pesan" style="min-width:220px">
    <button type="submit">Filter</button>
    @if(array_filter($filters))<a class="btn-ghost" href="{{ route('admin.aturan', ['qv' => $qv->id]) }}">Reset</a>@endif
    <span class="muted small">{{ $rules->count() }} aturan ditampilkan</span>
    <a class="btn-primary-link" href="{{ route('admin.aturan.baru', ['qv' => $qv->id]) }}" style="margin-left:auto">+ Buat aturan baru</a>
  </form>

  @foreach ($rules as $r)
    <form method="post" action="{{ route('admin.aturan.cepat', $r) }}" id="qc{{ $r->id }}" hidden>@csrf</form>
    <form method="post" action="{{ route('admin.aturan.toggle', $r) }}" id="tg{{ $r->id }}" hidden>@csrf</form>
  @endforeach

  <form method="post" action="{{ route('admin.aturan.toggle-massal') }}" id="bulk">@csrf
    <input type="hidden" name="qv" value="{{ $qv->id }}"></form>
  <div class="row-form" style="margin:10px 0">
    <label class="small"><input type="checkbox" id="rule-all"> pilih semua terlihat</label>
    <button form="bulk" type="submit" name="aksi" value="aktif" class="btn-ghost">aktifkan terpilih</button>
    <button form="bulk" type="submit" name="aksi" value="nonaktif" class="btn-ghost">nonaktifkan terpilih</button>
  </div>

  @forelse ($groups as $groupName => $groupRules)
    <details class="rule-group" open>
      <summary style="cursor:pointer;font-weight:600;padding:6px 0">
        {{ $groupName }}
        <span class="muted small">({{ $groupRules->where('active', true)->count() }}/{{ $groupRules->count() }} menyala)</span>
      </summary>
      <div class="tablewrap">
      <table class="tbl">
        <thead><tr>
          <th style="width:28px"></th><th style="width:70px">Tingkat</th>
          <th>Pesan yang tampil ke pemeriksa</th>
          <th style="width:110px" class="num" title="Dari hasil pemeriksaan terakhir">Kena</th>
          <th style="width:240px">Keterangan / ambang</th>
          <th style="width:150px"></th>
        </tr></thead>
        <tbody>
        @foreach ($groupRules as $r)
          <tr class="{{ $r->active ? '' : 'row-off' }}">
            <td><input form="bulk" type="checkbox" class="rule-pick" name="ids[]" value="{{ $r->id }}"></td>
            <td>
              <span class="flag {{ $r->severity }}">{{ Istilah::severity($r->severity) }}</span>
              @if($ruleErrs[$r->id])<span class="flag error" title="sintaks: {{ $ruleErrs[$r->id] }}">!</span>@endif
              @if(isset($testRes[$r->id]) && $testRes[$r->id]['pass'] < $testRes[$r->id]['total'])
                <a class="flag error" style="display:inline-block" href="{{ route('admin.aturan.edit', $r) }}#contoh-uji"
                  title="Contoh uji tidak sesuai harapan">uji {{ $testRes[$r->id]['pass'] }}/{{ $testRes[$r->id]['total'] }}</a>
              @endif
            </td>
            <td>
              {{ $r->message }}
              <div class="mono muted small">{{ $r->code }}@if($r->target_var) · {{ $r->target_var }}@endif</div>
              <div class="bchips">@include('admin.partials._block_chips', ['codes' => $links['rules'][$r->id] ?? [], 'qvId' => $qv->id])</div>
            </td>
            <td class="num">
              @php($n = (int) ($hits[$r->id] ?? 0))
              @if($n > 0)
                <a href="{{ route('pengolahan.index', ['rule' => $r->code]) }}" title="Lihat sampel yang kena di Pengolahan">{{ $n }} sampel</a>
              @else
                <span class="muted">0</span>
              @endif
            </td>
            <td>
              <input form="qc{{ $r->id }}" name="note" value="{{ $r->note }}" placeholder="apa yang diperiksa aturan ini…"
                style="width:100%;font-size:.86rem">
              @if($r->soleThreshold() !== null)
                <label class="small muted">ambang
                  <input form="qc{{ $r->id }}" name="threshold" value="{{ $r->soleThreshold() }}"
                    inputmode="decimal" class="mono thr-live" style="width:88px"
                    data-expr="{{ $r->expr }}" data-old="{{ $r->soleThreshold() }}" data-rb="{{ $r->row_block }}"
                    data-now="{{ (int) ($hits[$r->id] ?? 0) }}">
                </label>
                <div class="small muted thr-out" aria-live="polite"></div>
              @endif
            </td>
            <td class="right nowrap">
              <button form="qc{{ $r->id }}" type="submit" class="btn-ghost">Simpan</button>
              <button form="tg{{ $r->id }}" type="submit" class="btn-ghost">{{ $r->active ? 'matikan' : 'nyalakan' }}</button>
              <a class="btn-ghost" href="{{ route('admin.aturan.edit', $r) }}">Ubah</a>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
      </div>
    </details>
  @empty
    <p class="muted">Tidak ada aturan yang cocok dengan filter.</p>
  @endforelse
</section>

@push('scripts')
<script>
(function () {
  // Inc 158: dampak ambang langsung — ubah angka, lihat berapa sampel yang akan kena sebelum menyimpan
  var csrf = document.querySelector('meta[name=csrf-token]').content;
  var nf = function (n) { return Number(n).toLocaleString("id-ID"); };
  document.querySelectorAll(".thr-live").forEach(function (inp) {
    var out = inp.closest("td").querySelector(".thr-out"), timer = null, seq = 0;
    var reOld = inp.dataset.old.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    inp.addEventListener("input", function () {
      clearTimeout(timer);
      var v = inp.value.trim().replace(",", ".");
      if (v === inp.dataset.old || !/^-?\d+(\.\d+)?$/.test(v)) { out.textContent = ""; return; }
      timer = setTimeout(function () {
        var my = ++seq;
        var expr = inp.dataset.expr.replace(new RegExp("((?:>=|<=|>|<)\\s*)" + reOld + "(?![\\d.])", "g"), "$1" + v);
        out.textContent = "menghitung…";
        fetch("{{ route('admin.aturan.pratinjau') }}", {
          method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-TOKEN": csrf },
          body: new URLSearchParams({ qv: "{{ $qv->id }}", expr: expr, row_block: inp.dataset.rb })
        }).then(function (r) { return r.json(); }).then(function (d) {
          if (my !== seq) return;
          if (!d.ok) { out.textContent = "✕ " + d.error; return; }
          out.innerHTML = "Dengan " + v + ": kena <strong>" + nf(d.fire_docs) + "</strong> sampel"
            + " (sekarang " + nf(inp.dataset.now) + ")" + (d.truncated ? ", dari " + nf(d.docs) + " sampel pertama" : "");
        }).catch(function (e) { if (my === seq) out.textContent = e.message; });
      }, 500);
    });
  });

  var all = document.getElementById("rule-all");
  if (all) all.addEventListener("change", function () {
    document.querySelectorAll(".rule-pick").forEach(function (c) { c.checked = all.checked; });
  });
})();
</script>
@endpush
@endif
@endsection
