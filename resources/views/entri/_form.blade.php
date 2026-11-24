{{-- Isi utama formulir entri (blok per blok). Dipakai dua tempat:
     1. Halaman entri petugas (entri/document.blade.php)
     2. Pratinjau di sisi admin (admin/pratinjau.blade.php) —Inc 143
     Karena sama, pratinjau admin dijamin identik dengan layar petugas. --}}
<div class="entry-main" data-doc="{{ $doc->id }}" data-can-edit="{{ $canEdit ? '1' : '0' }}">
  @foreach ($state['blocks'] as $b)
  <section class="panel block" data-block="{{ $b['code'] }}" id="blok-{{ $b['code'] }}">
    <h2 class="block-head" data-block-toggle>
      <span class="block-caret" aria-hidden="true">▾</span>
      {{ $b['code'] }} — {{ $b['title'] }}
      @if($b['n_err'])<span class="flag error" style="display:inline-block">{{ $b['n_err'] }} kesalahan</span>@endif
      @if($b['n_warn'])<span class="flag warning" style="display:inline-block">{{ $b['n_warn'] }} peringatan</span>@endif
    </h2>
    <div class="block-body">

    @if(in_array($b['repeat'], ['art','list','list_art']))
    <div class="roster-scroll">
    <table class="roster">
      <thead>
        <tr>
          <th>{{ $b['repeat'] === 'list' ? 'Rincian' : ($b['repeat'] === 'list_art' ? 'Komoditas / anggota' : 'Anggota rumah tangga') }}</th>
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
    @if(in_array($b["repeat"], ["art","list_art"]))<p class="muted small">Jumlah baris mengikuti isian banyaknya anggota rumah tangga. Ubah isian itu lalu klik <em>Terapkan jumlah anggota</em> di bawahnya — baris bertambah / berkurang otomatis (isian anggota yang dihapus tak bisa dikembalikan).</p>@endif

    @else
    <div class="fields-grid">
    @foreach ($b['questions'] as $q)
    <div class="field {{ $q['skipped'] ? 'is-skipped' : '' }}" data-var="{{ $q['var'] }}" data-qid="{{ $q['id'] }}">
      <label>
        <span class="q-code mono">{{ $q['code'] }}</span>
        <span class="q-label">{{ $q['label'] }}</span>
        @if($q['unit'])<span class="q-unit">{{ $q['unit'] }}</span>@endif
        @if($q['help'])
        <span class="q-help">
          <span class="q-help-btn" tabindex="0">?</span>
          <span class="q-help-tip">{{ $q['help'] }}</span>
        </span>
        @endif
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
        <button type="button" id="apply-art" class="btn-ghost" style="margin-top:6px">Terapkan jumlah anggota</button>
        <span class="muted small" id="apply-art-note">tabel roster &amp; makanan-jadi disesuaikan ke angka ini</span>
      @endif
      <div class="q-flags">
        @foreach ($q['warnings'] as $w)<span class="flag {{ $w['status'] }}">{{ $w['message'] }}</span>@endforeach
      </div>
    </div>
    @endforeach
    </div>
    @endif

    <div class="block-stepper">
      @if(!$loop->first)
      <button type="button" class="btn-ghost" data-step-prev="{{ $state['blocks'][$loop->index - 1]['code'] }}">← Blok sebelumnya</button>
      @else
      <span></span>
      @endif
      @if(!$loop->last)
      <button type="button" class="btn-ghost" data-step-next="{{ $state['blocks'][$loop->index + 1]['code'] }}">Blok berikutnya →</button>
      @endif
    </div>
    </div>
  </section>
  @endforeach
</div>
