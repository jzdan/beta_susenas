{{-- Inc 154: versi milik siklus yang sudah dikunci = hanya-lihat. Server menolak perubahan
     (GuardsLockedVersion, 423); di sini formulir pengubah disembunyikan supaya tidak
     memancing klik. Formulir yang tetap boleh (jadikan bawaan, periksa, duplikasi) diberi .ro-allow. --}}
@if($roQv && $roQv->locked())
<div class="alert ro-note">Periode versi <span class="mono">{{ $roQv->code }}</span> sudah dikunci, jadi kuesioner dan aturannya
  <strong>hanya bisa dilihat</strong>. Untuk mengubah, buka kunci periodenya dulu di Laporan &amp; penutupan.</div>
@push('scripts')
<style>
  .content form[method="post"]:not(.ro-allow), .content button[form]:not(.ro-allow) { display: none !important; }
  .content input[form], .content select[form] { pointer-events: none; opacity: .7; }
</style>
@endpush
@endif
