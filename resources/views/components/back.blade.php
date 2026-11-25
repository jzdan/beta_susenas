{{-- Tombol kembali seragam. href = tujuan pasti (halaman induk).
     smart: bila datang dari halaman lain di aplikasi ini, kembali ke halaman itu persis
     (mis. Daftar Sampel lengkap dengan filternya); kalau tidak (dibuka langsung, atau sesudah
     menyimpan di halaman yang sama), ke href. Lihat [data-back-smart] di public/js/app.js. --}}
@props(['href', 'label' => 'Kembali', 'smart' => false])
<a {{ $attributes->merge(['class' => 'back-link']) }} href="{{ $href }}" @if($smart) data-back-smart @endif>← {{ $label }}</a>
