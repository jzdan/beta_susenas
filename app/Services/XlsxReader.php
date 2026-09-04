<?php

namespace App\Services;

/**
 * Pembaca .xlsx sederhana tanpa dependency — cukup untuk impor tabel referensi
 * (komoditas, harga, sampel). Membaca satu sheet menjadi baris asosiatif
 * berdasarkan header di baris pertama.
 */
class XlsxReader
{
    /**
     * @return array<int, array<string,string>>  baris (tanpa header), key = nama kolom header
     *
     * @throws \RuntimeException bila berkas tak bisa dibuka / bukan xlsx
     */
    public static function rows(string $path, ?string $sheet = null): array
    {
        $z = new \ZipArchive;
        if ($z->open($path) !== true) {
            throw new \RuntimeException('Berkas Excel tidak bisa dibuka.');
        }

        $shared = [];
        if ($x = $z->getFromName('xl/sharedStrings.xml')) {
            if (preg_match_all('/<si>(.*?)<\/si>/s', $x, $m)) {
                foreach ($m[1] as $si) {
                    preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm);
                    $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1);
                }
            }
        }

        $wb = (string) $z->getFromName('xl/workbook.xml');
        $rels = (string) $z->getFromName('xl/_rels/workbook.xml.rels');
        preg_match_all('/<sheet [^>]*name="([^"]+)"[^>]*r:id="([^"]+)"/', $wb, $sm, PREG_SET_ORDER);
        $relMap = [];
        preg_match_all('/<Relationship [^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/', $rels, $rm, PREG_SET_ORDER);
        foreach ($rm as $r) {
            $relMap[$r[1]] = $r[2];
        }

        $target = null;
        foreach ($sm as $s) {
            if ($sheet === null || strcasecmp($s[1], $sheet) === 0) {
                $target = $relMap[$s[2]] ?? null;
                break;
            }
        }
        if (! $target) {
            throw new \RuntimeException($sheet ? "Sheet '{$sheet}' tidak ditemukan." : 'Sheet tidak ditemukan.');
        }
        $target = 'xl/'.ltrim(str_replace('\\', '/', $target), '/');
        $xml = (string) $z->getFromName($target);
        $z->close();

        $matrix = [];
        preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $xml, $rows);
        foreach ($rows[1] as $row) {
            preg_match_all('/<c[^>]*r="([A-Z]+)\d+"([^>]*)>(?:<v>(.*?)<\/v>|<is><t[^>]*>(.*?)<\/t><\/is>)?<\/c>/s', $row, $cells, PREG_SET_ORDER);
            $line = [];
            $max = 0;
            foreach ($cells as $c) {
                $col = 0;
                foreach (str_split($c[1]) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $isStr = str_contains($c[2], 't="s"');
                $val = ($c[3] ?? '') !== '' ? $c[3] : ($c[4] ?? '');
                if ($isStr && $val !== '') {
                    $val = $shared[(int) $val] ?? $val;
                }
                $line[$col] = trim(html_entity_decode((string) $val, ENT_QUOTES | ENT_XML1));
                $max = max($max, $col);
            }
            $flat = [];
            for ($i = 1; $i <= $max; $i++) {
                $flat[] = $line[$i] ?? '';
            }
            $matrix[] = $flat;
        }

        // baris pertama non-kosong = header
        $header = null;
        $out = [];
        foreach ($matrix as $flat) {
            if ($header === null) {
                if (implode('', $flat) === '') {
                    continue;
                }
                $header = array_map(fn ($h) => strtolower(trim($h)), $flat);

                continue;
            }
            if (implode('', $flat) === '') {
                continue;
            }
            $assoc = [];
            foreach ($header as $i => $key) {
                if ($key !== '') {
                    $assoc[$key] = $flat[$i] ?? '';
                }
            }
            $out[] = $assoc;
        }

        return $out;
    }

    /** Baca baris dari berkas .xlsx atau .csv (deteksi dari isi/ekstensi). */
    public static function fromUpload(\Illuminate\Http\UploadedFile $file, ?string $sheet = null): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'csv' || $ext === 'txt') {
            return self::csvRows((string) file_get_contents($file->getRealPath()));
        }

        return self::rows($file->getRealPath(), $sheet);
    }

    /** @return array<int, array<string,string>> */
    public static function csvRows(string $data): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim(ltrim($data, "\u{FEFF}")));
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines) ?? ''));
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line);
            $assoc = [];
            foreach ($header as $i => $key) {
                if ($key !== '') {
                    $assoc[$key] = trim((string) ($cells[$i] ?? ''));
                }
            }
            $out[] = $assoc;
        }

        return $out;
    }
}
