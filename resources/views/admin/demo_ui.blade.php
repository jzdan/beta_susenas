<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Demo UI Entri · Percobaan</title>
  <script>
    // pra-render: pasang tema tersimpan sebelum render agar tidak berkedip
    try {
      if (localStorage.getItem('susenas-demo-theme') === 'dark'
          || (!localStorage.getItem('susenas-demo-theme') && matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
      }
    } catch (e) {}
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    // dipasang setelah cdn tailwind dimuat — strategi 'class' + warna diambil
    // persis dari token tema aplikasi (public/css/app.css --accent/--ok/--warn/--err)
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            bps: { DEFAULT: '#1f5aa8', strong: '#1b4f95', soft: '#e6eef9', dark: '#7fb0e0', darkstrong: '#6ba3d8', darksoft: '#182838' },
          },
        },
      },
    };
  </script>
  <script defer src="https://unpkg.com/alpinejs@3/dist/cdn.min.js"></script>
</head>
<body class="bg-[#eceef3] dark:bg-[#11151c] text-[#141b26] dark:text-[#e8eaef] font-sans transition-colors">

<div class="bg-[#f7edda] dark:bg-[#2c2413] text-[#96660f] dark:text-[#cfa851] text-sm text-center py-1.5 font-medium flex items-center justify-center gap-3 flex-wrap px-3">
  <span>Halaman percobaan tampilan — data contoh, tidak terhubung ke entri sungguhan. Bisa dihapus kapan saja.</span>
  <button type="button"
    onclick="document.documentElement.classList.toggle('dark'); localStorage.setItem('susenas-demo-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light')"
    class="px-2.5 py-0.5 rounded border border-current/30 text-xs shrink-0">Ganti tema terang/gelap</button>
</div>

@php
// Data contoh saja — bukan dari database.
$blocks = [
  [
    'code' => 'I', 'title' => 'Keterangan Tempat', 'repeat' => 'ruta', 'n_err' => 0, 'n_warn' => 0,
    'questions' => [
      ['code' => '101', 'label' => 'Nama Kepala Rumah Tangga', 'unit' => '', 'value' => 'Budi Santoso', 'help' => 'Isi sesuai KTP/KK, tanpa gelar.', 'warn' => null],
      ['code' => '102', 'label' => 'Jumlah anggota rumah tangga', 'unit' => 'orang', 'value' => '4', 'help' => 'Termasuk ART yang sedang bepergian < 6 bulan.', 'warn' => null],
      ['code' => '103', 'label' => 'Alamat', 'unit' => '', 'value' => 'Jl. Merpati No. 12', 'help' => '', 'warn' => null],
    ],
  ],
  [
    'code' => 'II', 'title' => 'Keterangan Perumahan', 'repeat' => 'ruta', 'n_err' => 1, 'n_warn' => 0,
    'questions' => [
      ['code' => '201', 'label' => 'Luas lantai', 'unit' => 'm²', 'value' => '45', 'help' => 'Luas lantai yang ditempati, tidak termasuk teras terbuka.', 'warn' => null],
      ['code' => '202', 'label' => 'Jenis atap terluas', 'unit' => '', 'value' => '', 'help' => '', 'warn' => 'Belum diisi'],
      ['code' => '203', 'label' => 'Sumber air minum utama', 'unit' => '', 'value' => 'Air kemasan/isi ulang', 'help' => 'Pilih sumber yang paling banyak dipakai sehari-hari.', 'warn' => null],
    ],
  ],
  [
    'code' => 'IV.1', 'title' => 'Konsumsi Makanan (daftar komoditas)', 'repeat' => 'list', 'n_err' => 0, 'n_warn' => 2,
    'rows' => [
      ['label' => 'Beras', 'qty' => '15', 'unit' => 'kg', 'value_rp' => '195000', 'warn' => null],
      ['label' => 'Telur ayam ras', 'qty' => '2', 'unit' => 'kg', 'value_rp' => '56000', 'warn' => null],
      ['label' => 'Minyak goreng', 'qty' => '1', 'unit' => 'liter', 'value_rp' => '120000', 'warn' => 'Harga di atas rentang wajar'],
      ['label' => 'Gula pasir', 'qty' => '0.5', 'unit' => 'kg', 'value_rp' => '9000', 'warn' => null],
      ['label' => 'Rokok kretek filter', 'qty' => '2', 'unit' => 'bungkus', 'value_rp' => '64000', 'warn' => 'Cek kewajaran jumlah'],
    ],
  ],
];
@endphp

<div x-data="{
       step: 0,
       blocks: {{ count($blocks) }},
       filter: '',
       onlyIssues: false,
     }" class="max-w-6xl mx-auto p-4 md:p-6">

  <h1 class="text-xl font-semibold mb-1">Entri Kuesioner <span class="text-[#767f8d] dark:text-[#858c98] font-normal">· contoh tampilan</span></h1>
  <p class="text-sm text-[#767f8d] dark:text-[#858c98] mb-5">VSEN26.K · Blok Sensus 001 · Rumah Tangga 03 · isian #999</p>

  <div class="grid md:grid-cols-[220px_1fr] gap-5">

    {{-- Sidebar wizard --}}
    <nav class="bg-white dark:bg-[#1b212b] rounded-xl shadow-sm border border-[#e3e6ec] dark:border-[#2a313c] p-2 h-fit md:sticky md:top-4">
      @foreach ($blocks as $i => $b)
      <button type="button" @click="step = {{ $i }}"
        class="w-full text-left px-3 py-2.5 rounded-lg mb-1 flex items-center gap-2 transition"
        :class="step === {{ $i }} ? 'bg-bps dark:bg-bps-dark text-white dark:text-[#10161d]' : 'hover:bg-[#f4f6f9] dark:hover:bg-[#222a35] text-[#454f5d] dark:text-[#b4bac4]'">
        <span class="w-6 h-6 rounded-full text-xs flex items-center justify-center font-medium"
          :class="step === {{ $i }} ? 'bg-white/25 dark:bg-black/15' : 'bg-[#e3e6ec] dark:bg-[#2a313c]'">{{ $i + 1 }}</span>
        <span class="flex-1">
          <span class="block text-sm font-medium">Blok {{ $b['code'] }}</span>
          <span class="block text-xs opacity-75">{{ $b['title'] }}</span>
        </span>
        @if($b['n_err'])<span class="text-[11px] px-1.5 py-0.5 rounded-full bg-[#a83a3a] dark:bg-[#db7c7c] text-white dark:text-[#10161d]">{{ $b['n_err'] }}</span>@endif
        @if($b['n_warn'])<span class="text-[11px] px-1.5 py-0.5 rounded-full bg-[#96660f] dark:bg-[#cfa851] text-white dark:text-[#10161d]">{{ $b['n_warn'] }}</span>@endif
      </button>
      @endforeach
    </nav>

    {{-- Panel utama --}}
    <div>
      {{-- progress + filter --}}
      <div class="bg-white dark:bg-[#1b212b] rounded-xl shadow-sm border border-[#e3e6ec] dark:border-[#2a313c] p-4 mb-4">
        <div class="h-1.5 bg-[#f4f6f9] dark:bg-[#222a35] rounded-full overflow-hidden mb-3">
          <div class="h-full bg-bps dark:bg-bps-dark transition-all" :style="`width: ${((step+1)/blocks)*100}%`"></div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <input type="text" x-model="filter" placeholder="Cari rincian…"
            class="flex-1 min-w-[160px] bg-white dark:bg-[#11151c] border border-[#c9cfda] dark:border-[#3a424f] rounded-lg px-3 py-1.5 text-sm text-[#141b26] dark:text-[#e8eaef] placeholder:text-[#767f8d] dark:placeholder:text-[#858c98] focus:outline-bps dark:focus:outline-bps-dark">
          <label class="flex items-center gap-1.5 text-sm text-[#454f5d] dark:text-[#b4bac4]">
            <input type="checkbox" x-model="onlyIssues" class="rounded accent-bps dark:accent-bps-dark"> Hanya error/kosong
          </label>
        </div>
      </div>

      @foreach ($blocks as $i => $b)
      <div x-show="step === {{ $i }}" x-cloak class="bg-white dark:bg-[#1b212b] rounded-xl shadow-sm border border-[#e3e6ec] dark:border-[#2a313c] p-5">
        <h2 class="font-semibold mb-4">Blok {{ $b['code'] }} — {{ $b['title'] }}</h2>

        @if ($b['repeat'] === 'list')
        {{-- grid padat ala tabel untuk blok daftar --}}
        <div class="overflow-x-auto border border-[#e3e6ec] dark:border-[#2a313c] rounded-lg">
          <table class="w-full text-sm">
            <thead class="bg-[#f4f6f9] dark:bg-[#222a35] text-[#767f8d] dark:text-[#858c98] text-xs uppercase">
              <tr>
                <th class="text-left px-3 py-2 font-medium">Komoditas</th>
                <th class="text-right px-3 py-2 font-medium">Banyaknya</th>
                <th class="text-left px-3 py-2 font-medium">Satuan</th>
                <th class="text-right px-3 py-2 font-medium">Nilai (Rp)</th>
                <th class="px-3 py-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[#e3e6ec] dark:divide-[#2a313c]">
              @foreach ($b['rows'] as $r)
              <tr x-show="!filter || '{{ Str::lower($r['label']) }}'.includes(filter.toLowerCase())"
                  x-cloak class="{{ $r['warn'] ? 'bg-[#f7edda] dark:bg-[#2c2413]' : '' }} hover:bg-[#f4f6f9] dark:hover:bg-[#222a35]">
                <td class="px-3 py-1.5">{{ $r['label'] }}</td>
                <td class="px-3 py-1.5 text-right"><input value="{{ $r['qty'] }}" class="w-16 text-right bg-white dark:bg-[#11151c] border border-[#e3e6ec] dark:border-[#2a313c] rounded px-1.5 py-0.5 text-[#141b26] dark:text-[#e8eaef]"></td>
                <td class="px-3 py-1.5 text-[#767f8d] dark:text-[#858c98]">{{ $r['unit'] }}</td>
                <td class="px-3 py-1.5 text-right"><input value="{{ $r['value_rp'] }}" class="w-24 text-right bg-white dark:bg-[#11151c] border border-[#e3e6ec] dark:border-[#2a313c] rounded px-1.5 py-0.5 text-[#141b26] dark:text-[#e8eaef]"></td>
                <td class="px-3 py-1.5 text-center">
                  @if($r['warn'])
                  <span x-data="{ open: false }" class="relative inline-block">
                    <button type="button" @mouseenter="open = true" @mouseleave="open = false"
                      class="w-5 h-5 rounded-full bg-[#96660f] dark:bg-[#cfa851] text-white dark:text-[#10161d] text-xs font-bold">!</button>
                    <span x-show="open" x-cloak
                      class="absolute right-0 z-10 mt-1 w-48 bg-[#141b26] dark:bg-[#e8eaef] text-white dark:text-[#10161d] text-xs rounded-lg px-2.5 py-1.5 shadow-lg">
                      {{ $r['warn'] }}
                    </span>
                  </span>
                  @endif
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        @else
        {{-- field ruta biasa, dengan tooltip rincian --}}
        <div class="grid sm:grid-cols-2 gap-4">
          @foreach ($b['questions'] as $q)
          <div x-show="!filter || '{{ Str::lower($q['label']) }}'.includes(filter.toLowerCase())"
               x-data="{ open: false }"
               class="{{ $q['warn'] ? 'border-[#a83a3a]/40 dark:border-[#db7c7c]/40 bg-[#f7e7e7] dark:bg-[#2e1c1c]' : 'border-[#e3e6ec] dark:border-[#2a313c]' }} border rounded-lg p-3">
            <div class="flex items-start justify-between gap-2 mb-1.5">
              <label class="text-sm font-medium text-[#454f5d] dark:text-[#b4bac4]">
                <span class="text-[#767f8d] dark:text-[#858c98] font-mono text-xs mr-1">{{ $q['code'] }}</span>{{ $q['label'] }}
                @if($q['unit'])<span class="text-[#767f8d] dark:text-[#858c98] text-xs">({{ $q['unit'] }})</span>@endif
              </label>
              @if($q['help'])
              <span class="relative">
                <button type="button" @mouseenter="open = true" @mouseleave="open = false"
                  class="w-5 h-5 rounded-full bg-[#e3e6ec] dark:bg-[#2a313c] text-[#454f5d] dark:text-[#b4bac4] text-xs">?</button>
                <span x-show="open" x-cloak
                  class="absolute right-0 z-10 mt-1 w-56 bg-[#141b26] dark:bg-[#e8eaef] text-white dark:text-[#10161d] text-xs rounded-lg px-2.5 py-1.5 shadow-lg">
                  {{ $q['help'] }}
                </span>
              </span>
              @endif
            </div>
            <input value="{{ $q['value'] }}" placeholder="{{ $q['warn'] ?: '—' }}"
              class="w-full bg-white dark:bg-[#11151c] border rounded-lg px-2.5 py-1.5 text-sm text-[#141b26] dark:text-[#e8eaef] placeholder:text-[#767f8d] dark:placeholder:text-[#858c98] {{ $q['warn'] ? 'border-[#a83a3a] dark:border-[#db7c7c]' : 'border-[#c9cfda] dark:border-[#3a424f]' }}">
            @if($q['warn'])<p class="text-xs text-[#a83a3a] dark:text-[#db7c7c] mt-1">{{ $q['warn'] }}</p>@endif
          </div>
          @endforeach
        </div>
        @endif

        <div class="flex justify-between mt-5 pt-4 border-t border-[#e3e6ec] dark:border-[#2a313c]">
          <button type="button" @click="step = Math.max(0, step - 1)" :disabled="step === 0"
            class="px-4 py-2 rounded-lg text-sm border border-[#c9cfda] dark:border-[#3a424f] text-[#454f5d] dark:text-[#b4bac4] disabled:opacity-40">← Kembali</button>
          <button type="button" @click="step = Math.min(blocks - 1, step + 1)" :disabled="step === blocks - 1"
            class="px-4 py-2 rounded-lg text-sm bg-bps dark:bg-bps-dark text-white dark:text-[#10161d] disabled:opacity-40">Lanjut →</button>
        </div>
      </div>
      @endforeach

    </div>
  </div>
</div>

</body>
</html>
