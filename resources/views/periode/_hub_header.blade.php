{{-- Kepala hub siklus (Inc 154): jejak Periode › tahun › triwulan › survei, pemilih survei
     dalam triwulan yang sama, dan tab. Dipakai halaman hub baru (Ringkasan, Peserta) DAN
     halaman lama yang tampil sebagai tab (Metadata, Aturan, Daftar Sampel, Pengolahan,
     Persiapan, Laporan siklus) — butuh $hubCycle, $hubSiblings, $hubVersion, $hubTab. --}}
@php($hubTabsList = \App\Http\Controllers\PeriodeController::hubTabs($hubCycle, $hubVersion))
<div class="hub-head">
  <a class="back-link" href="{{ route('periode.triwulan', [$hubCycle->year(), $hubCycle->quarter()]) }}">← Kembali ke TW{{ $hubCycle->quarter() }} {{ $hubCycle->year() }}</a>
  <div class="crumb">
    <a href="{{ route('periode.index') }}">Periode</a> ›
    <a href="{{ route('periode.tahun', $hubCycle->year()) }}">{{ $hubCycle->year() }}</a> ›
    <a href="{{ route('periode.triwulan', [$hubCycle->year(), $hubCycle->quarter()]) }}">TW{{ $hubCycle->quarter() }}</a> ›
    {{ $hubCycle->label() }}
  </div>
  <div class="hub-title">
    <h1>{{ $hubCycle->label() }} <small class="muted">TW{{ $hubCycle->quarter() }} {{ $hubCycle->year() }} · {{ $hubCycle->monthName() }}</small></h1>
    <span class="chip p-{{ $hubCycle->status }}">{{ Istilah::cycleStatus($hubCycle->status) }}</span>
  </div>
  <p class="muted small hub-meta">
    @if($hubCycle->field_start && $hubCycle->field_end)Lapangan {{ $hubCycle->field_start->format('d M') }} s.d. {{ $hubCycle->field_end->format('d M Y') }} · @endif
    {{ Istilah::CYCLE_STATUS_HELP[$hubCycle->status] ?? '' }}
    @if($hubVersion) · versi kuesioner <span class="mono">{{ $hubVersion->code }}</span>@endif
  </p>
  @if($hubSiblings->count() > 1)
  <nav class="hub-switch" aria-label="Survei lain di triwulan ini">
    @foreach ($hubSiblings as $sib)
      <a href="{{ route('periode.hub', $sib) }}" class="{{ $sib->id === $hubCycle->id ? 'on' : '' }}">{{ $sib->label() }}</a>
    @endforeach
  </nav>
  @endif
  <nav class="hub-tabs">
    @foreach ($hubTabsList as $key => $t)
      <a href="{{ $t[1] }}" class="{{ ($hubTab ?? '') === $key ? 'on' : '' }}">{{ $t[0] }}</a>
    @endforeach
  </nav>
</div>
