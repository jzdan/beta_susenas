{{-- Inc 167 — tombol "? Panduan" + carousel langkah bergambar untuk satu halaman (kunci di App\Support\Panduan).
     Muncul sendiri sekali per pengguna (per versi isi) — lihat [data-panduan] di public/js/app.js.
     Belum ada langkah: admin melihat tautan "Buat panduan", pengguna lain tidak melihat apa-apa. --}}
@props(['key', 'label' => '? Panduan', 'openNow' => false])
@php
  $slides = \App\Models\GuideSlide::forPage($key);
  $isAdmin = auth()->user()?->role === 'admin';
  $pageLabel = \App\Support\Panduan::label($key);
  $ver = \App\Models\GuideSlide::versionOf($slides);
@endphp
@if($slides->isNotEmpty())
<button type="button" class="panduan-btn" data-panduan-open="{{ $key }}" title="Buka panduan">{{ $label }}</button>
@push('panduan')
<dialog class="panduan" data-panduan="{{ $key }}" data-version="{{ $ver }}" data-auto="{{ $openNow || ! \App\Models\GuideSlide::seenBy(auth()->id(), $key, $ver) ? 1 : 0 }}"
  data-seen-url="{{ route('panduan.dilihat', $key) }}" aria-label="Panduan {{ $pageLabel }}">
  <div class="panduan-head">
    <b>Panduan: {{ $pageLabel }}</b>
    <span class="panduan-step" aria-live="polite">1 / {{ $slides->count() }}</span>
    <button type="button" class="panduan-close" data-panduan-close aria-label="Tutup panduan">×</button>
  </div>
  <div class="panduan-viewport">
    <div class="panduan-track">
      @foreach ($slides as $i => $s)
      <figure class="panduan-slide" aria-roledescription="langkah" aria-label="Langkah {{ $i + 1 }} dari {{ $slides->count() }}">
        @if($s->image_path)
        <a class="panduan-img" href="{{ route('panduan.gambar', $s) }}?v={{ $s->updated_at?->timestamp }}" target="_blank" rel="noopener"
           title="Buka gambar ukuran penuh"><img src="{{ route('panduan.gambar', $s) }}?v={{ $s->updated_at?->timestamp }}" alt="{{ $s->title }}" loading="lazy"></a>
        @endif
        <figcaption>
          @if($s->section)<div class="panduan-section">{{ $s->section }}</div>@endif
          <h3><span class="panduan-no">{{ $i + 1 }}</span> {{ $s->title }}</h3>
          @if($s->body)<p>{!! nl2br(e($s->body)) !!}</p>@endif
          @if($s->link)<p class="panduan-link"><a class="btn-ghost" href="{{ $s->link }}">Buka halaman ini →</a></p>@endif
        </figcaption>
      </figure>
      @endforeach
    </div>
  </div>
  <div class="panduan-foot">
    <button type="button" class="btn-ghost" data-panduan-prev>‹ Sebelumnya</button>
    <span class="panduan-dots">
      @foreach ($slides as $i => $s)<button type="button" data-panduan-dot="{{ $i }}" aria-label="Ke langkah {{ $i + 1 }}"></button>@endforeach
    </span>
    <button type="button" data-panduan-next>Berikutnya ›</button>
  </div>
  <p class="panduan-note">@unless($openNow)Panduan ini hanya muncul sendiri sekali — buka lagi kapan saja lewat tombol <b>? Panduan</b>.@endunless
    @if($isAdmin)<a href="{{ route('admin.panduan.edit', $key) }}">Ubah panduan ini</a>@endif</p>
</dialog>
@endpush
@elseif($isAdmin)
<a class="panduan-btn is-empty" href="{{ route('admin.panduan.edit', $key) }}" title="Halaman ini belum punya panduan">+ Buat panduan</a>
@endif
