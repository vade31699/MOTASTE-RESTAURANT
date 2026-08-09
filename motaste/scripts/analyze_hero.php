<?php
// Quick luminance analysis of public/hero.jpg by region.
if (!function_exists('imagecreatefromjpeg')) {
    echo "GD not available\n";
    exit(1);
}
$path = __DIR__ . '/../public/hero.jpg';
if (!file_exists($path)) {
    echo "hero.jpg not found at $path\n";
    exit(1);
}
$im = imagecreatefromjpeg($path);
if (!$im) {
    echo "Failed to decode hero.jpg\n";
    exit(1);
}
$w = imagesx($im);
$h = imagesy($im);
echo "size: {$w}x{$h}\n";

// Split into a 6x3 grid of columns x rows; report avg luminance 0-255.
$cols = 6;
$rows = 3;
for ($r = 0; $r < $rows; $r++) {
    $line = [];
    for ($c = 0; $c < $cols; $c++) {
        $x0 = (int)($w * $c / $cols);
        $x1 = (int)($w * ($c + 1) / $cols);
        $y0 = (int)($h * $r / $rows);
        $y1 = (int)($h * ($r + 1) / $rows);
        $s = 0; $n = 0;
        for ($x = $x0; $x < $x1; $x += 8) {
            for ($y = $y0; $y < $y1; $y += 8) {
                $rgb = imagecolorat($im, $x, $y);
                $r8 = ($rgb >> 16) & 255; $g8 = ($rgb >> 8) & 255; $b8 = $rgb & 255;
                $s += 0.299 * $r8 + 0.587 * $g8 + 0.114 * $b8;
                $n++;
            }
        }
        $line[] = $n ? round($s / $n) : 0;
    }
    echo "row{$r}: " . implode(' ', $line) . "\n";
}
// Whole-image average too
$s = 0; $n = 0;
for ($x = 0; $x < $w; $x += 8) {
    for ($y = 0; $y < $h; $y += 8) {
        $rgb = imagecolorat($im, $x, $y);
        $r8 = ($rgb >> 16) & 255; $g8 = ($rgb >> 8) & 255; $b8 = $rgb & 255;
        $s += 0.299 * $r8 + 0.587 * $g8 + 0.114 * $b8;
        $n++;
    }
}
echo "average: " . round($s / $n) . "\n";
