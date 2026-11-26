<?php

/**
 * Inc 165 — susun database/data/wilayah_bangkalan.csv (format Impor Wilayah: code,name,level)
 * dari GADM 4.1 tingkat 4 (nama desa/kelurahan). Sekali jalan; hasil CSV yang di-commit.
 *
 *   php -d memory_limit=4G scripts/build_wilayah_bangkalan.php <gadm41_IDN_4.json>
 *
 * Kode kecamatan = kode statistik BPS (010 Kamal … 180 Klampis). Kode desa SEMENTARA:
 * urut abjad nama di tiap kecamatan (001, 002, …) — master wilayah BPS (dengan kode desa resmi)
 * belum tersedia; timpa lewat Admin → Wilayah → Impor bila sudah ada.
 */
$src = $argv[1] ?? null;
if (! $src || ! is_file($src)) {
    fwrite(STDERR, "pakai: php scripts/build_wilayah_bangkalan.php <gadm41_IDN_4.json>\n");
    exit(1);
}

// nama kecamatan GADM (NAME_3) => [kode BPS 3 digit, nama baku]
$kec = [
    'Kamal' => ['010', 'Kamal'], 'Labang' => ['020', 'Labang'], 'Kwanyar' => ['030', 'Kwanyar'],
    'Modung' => ['040', 'Modung'], 'Blega' => ['050', 'Blega'], 'Konang' => ['060', 'Konang'],
    'Galis' => ['070', 'Galis'], 'TanahMerah' => ['080', 'Tanah Merah'], 'Tragah' => ['090', 'Tragah'],
    'Socah' => ['100', 'Socah'], 'Bangkalan' => ['110', 'Bangkalan'], 'Burneh' => ['120', 'Burneh'],
    'Arosbaya' => ['130', 'Arosbaya'], 'Geger' => ['140', 'Geger'], 'Kokop' => ['150', 'Kokop'],
    'Tanjungbumi' => ['160', 'Tanjung Bumi'], 'Sepulu' => ['170', 'Sepulu'], 'Klampis' => ['180', 'Klampis'],
];

// "BanyuAjuh" → "Banyu Ajuh", "GiliTimur" → "Gili Timur"
$spaced = fn (string $s) => trim(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', str_replace('_', ' ', $s)));

$json = json_decode(file_get_contents($src), true);
$desa = [];
foreach ($json['features'] as $f) {
    $p = $f['properties'];
    if (($p['NAME_2'] ?? '') !== 'Bangkalan') {
        continue;
    }
    if (! isset($kec[$p['NAME_3']])) {
        fwrite(STDERR, "kecamatan tak dikenal: {$p['NAME_3']}\n");
        exit(1);
    }
    $desa[$p['NAME_3']][] = $spaced($p['NAME_4']);
}

$out = fopen(__DIR__.'/../database/data/wilayah_bangkalan.csv', 'w');
fputcsv($out, ['code', 'name', 'level'], ',', '"', '');
fputcsv($out, ['3526', 'Bangkalan', 'kabupaten'], ',', '"', '');
$n = 0;
uasort($kec, fn ($a, $b) => strcmp($a[0], $b[0]));
foreach ($kec as $gadm => [$k3, $nama]) {
    fputcsv($out, ["3526{$k3}", $nama, 'kecamatan'], ',', '"', '');
    $list = array_values(array_unique($desa[$gadm] ?? []));
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($list as $i => $d) {
        fputcsv($out, [sprintf('3526%s%03d', $k3, $i + 1), $d, 'desa'], ',', '"', '');
        $n++;
    }
}
fclose($out);
echo "18 kecamatan, {$n} desa/kelurahan\n";
