{{-- Chip blok kuesioner yang dipakai sebuah aturan/agregat/turunan (Inc 155, App\Services\BlockLinks).
     $codes = daftar kode blok; $qvId = versi (chip menaut ke daftar aturan tersaring blok itu). --}}
@foreach ($codes as $code)
  <a class="bchip" href="{{ route('admin.aturan', ['qv' => $qvId, 'blok' => $code]) }}" title="Lihat semua aturan yang memakai Blok {{ $code }}">Blok {{ $code }}</a>
@endforeach
