{{-- Selector versi kuesioner yang sedang dilihat/disunting (Inc 131) — beda dari
     "Jadikan aktif" di Metadata: ini cuma ganti apa yang ditampilkan di halaman
     ini, tidak menyentuh versi yang dipakai pencacah lapangan untuk entri data. --}}
<div class="row-form" style="margin-bottom:10px;align-items:center">
  <label class="small">Versi kuesioner
    <select onchange="var u=new URL(location);u.searchParams.set('qv',this.value);location=u;">
      @foreach ($versions as $v)
        <option value="{{ $v->id }}" @selected($v->id === $qv->id)>{{ $v->code }}@if($v->id === $activeId) (aktif){{ '' }}@endif</option>
      @endforeach
    </select>
  </label>
  @if($qv->id !== $activeId)
    <span class="flag warning small">Menyunting versi TIDAK aktif — entri data pencacah lapangan tetap pakai versi aktif
      ({{ $versions->firstWhere('id', $activeId)->code ?? '?' }}).</span>
  @endif
</div>
