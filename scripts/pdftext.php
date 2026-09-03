<?php
// Minimal PDF text extractor: inflate every stream (incl. object streams), pull Tj/TJ strings.
$file = $argv[1] ?? exit("usage: php pdftext.php in.pdf [out.txt]\n");
$out  = $argv[2] ?? null;
$raw  = file_get_contents($file);

$chunks = [];
$off = 0;
while (($p = strpos($raw, 'stream', $off)) !== false) {
    $s = $p + 6;
    if ($raw[$s] === "\r") $s++;
    if ($raw[$s] === "\n") $s++;
    $e = strpos($raw, 'endstream', $s);
    if ($e === false) break;
    $blob = substr($raw, $s, $e - $s);
    $d = @gzuncompress($blob);
    if ($d === false) $d = @gzinflate($blob);
    if ($d === false && strlen($blob) > 2) $d = @gzinflate(substr($blob, 2));
    if ($d !== false && $d !== '') $chunks[] = $d;
    $off = $e + 9;
}

$text = '';
foreach ($chunks as $d) {
    // content-stream text operators
    if (preg_match_all('/BT(.*?)ET/s', $d, $bt)) {
        foreach ($bt[1] as $seg) {
            if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)|\[(?:[^\]\\\\]|\\\\.)*\]\s*TJ|(-?\d*\.?\d+)\s+TL|T\*/s', $seg, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $tok) {
                    $t = $tok[0];
                    if ($t === 'T*' || str_ends_with($t, 'TL')) { $text .= "\n"; continue; }
                    if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/s', $t, $pm)) {
                        foreach ($pm[1] as $p) {
                            $text .= strtr($p, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\', '\\n' => "\n", '\\r' => '', '\\t' => ' ', '\\055' => '-']);
                        }
                        $text .= ' ';
                    }
                }
            }
            $text .= "\n";
        }
    }
}
$text = preg_replace('/[ \t]{2,}/', ' ', $text);
$text = preg_replace('/\n{3,}/', "\n\n", $text);
if ($out) { file_put_contents($out, $text); echo 'wrote ' . strlen($text) . " chars\n"; }
else echo $text;
