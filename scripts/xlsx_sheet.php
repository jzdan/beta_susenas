<?php
// Dump satu sheet xlsx sebagai TSV. Usage: php xlsx_sheet.php file.xlsx "SheetName"
$file = $argv[1] ?? exit("usage: php xlsx_sheet.php file.xlsx SheetName\n");
$want = $argv[2] ?? null;
$z = new ZipArchive;
if ($z->open($file) !== true) exit("cannot open\n");

$ss = [];
if ($x = $z->getFromName('xl/sharedStrings.xml')) {
    if (preg_match_all('/<si>(.*?)<\/si>/s', $x, $m)) {
        foreach ($m[1] as $si) {
            preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm);
            $ss[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1);
        }
    }
}

$wb = $z->getFromName('xl/workbook.xml');
$rels = $z->getFromName('xl/_rels/workbook.xml.rels');
preg_match_all('/<sheet [^>]*name="([^"]+)"[^>]*r:id="([^"]+)"/', $wb, $sm, PREG_SET_ORDER);
$relMap = [];
preg_match_all('/<Relationship [^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/', $rels, $rm, PREG_SET_ORDER);
foreach ($rm as $r) $relMap[$r[1]] = $r[2];

$target = null;
foreach ($sm as $s) {
    if ($want === null || strcasecmp($s[1], $want) === 0) { $target = $relMap[$s[2]] ?? null; break; }
}
if (! $target) exit("sheet not found\n");
$target = 'xl/' . ltrim(str_replace('\\', '/', $target), '/');

$xml = $z->getFromName($target);
preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $xml, $rows);
foreach ($rows[1] as $row) {
    preg_match_all('/<c[^>]*r="([A-Z]+)\d+"([^>]*)>(?:<v>(.*?)<\/v>|<is><t[^>]*>(.*?)<\/t><\/is>)?<\/c>/s', $row, $cells, PREG_SET_ORDER);
    $line = [];
    $maxCol = 0;
    foreach ($cells as $c) {
        $col = 0;
        foreach (str_split($c[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
        $isStr = strpos($c[2], 't="s"') !== false;
        $val = $c[3] !== '' ? $c[3] : ($c[4] ?? '');
        if ($isStr && $val !== '') $val = $ss[(int) $val] ?? $val;
        $line[$col] = str_replace(["\t", "\n", "\r"], ' ', html_entity_decode((string) $val, ENT_QUOTES | ENT_XML1));
        $maxCol = max($maxCol, $col);
    }
    $out = [];
    for ($i = 1; $i <= $maxCol; $i++) $out[] = $line[$i] ?? '';
    echo implode("\t", $out) . "\n";
}
