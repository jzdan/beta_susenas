{{-- Panel ringkasan di samping formulir entri. Dipakai juga di pratinjau admin
     (Inc 143) — bagian yang butuh dokumen nyata (finalisasi, catatan, bukti
     kunjungan) disembunyikan saat pratinjau. --}}
<aside class="entry-side">
  <div class="panel sticky">
    <h2>Ringkasan</h2>
    <div class="summary-counts">
      <span class="cnt err" id="cnt-err">{{ $state['n_err'] }}</span> kesalahan
      <span class="cnt warn" id="cnt-warn">{{ $state['n_warn'] }}</span> peringatan
      <span class="saved-note" id="saved-note"></span>
    </div>
    @if($canEdit)
      <button type="button" id="next-issue" class="btn-ghost" style="margin-top:6px">↧ Ke masalah berikutnya</button>
    @endif

    <div class="entry-filter">
      <input type="text" id="entry-filter-text" placeholder="Cari rincian…" aria-label="Cari rincian">
      <label><input type="checkbox" id="entry-filter-issues"> Hanya yang bermasalah/kosong</label>
    </div>

    @php($pr = $state['progress'])
    <div class="entry-progress" id="entry-progress">
      <div class="prog-label">Kelengkapan isian
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

    <h3>Peringatan tingkat sampel</h3>
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

    @if($canEdit && in_array(auth()->user()->role, ['operator','pml','pj','admin']) && $doc->status !== 'bersih')
    <form method="post" action="{{ route('entri.finalize', $doc) }}" class="mt">
      @csrf
      <button type="submit" @disabled($state['n_err'] > 0)>
        {{ $doc->status === 'dikembalikan' ? 'Kirim ulang ke pemeriksa' : 'Finalisasi (tandai selesai)' }}
      </button>
    </form>
    @if($state['n_err'] > 0)<p class="muted small">Perbaiki semua kesalahan dulu.</p>@endif
    @endif
  </div>

  @if($canEdit)
    <div class="panel">
      @include('_catatan', ['from' => 'entri'])
    </div>

    @include('entri._bukti_kunjungan')
  @endif
</aside>
