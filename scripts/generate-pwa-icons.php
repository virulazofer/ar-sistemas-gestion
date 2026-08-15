<?php

$dir = __DIR__.'/../public/icons';
if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}

function makeIcon(string $path, int $size, bool $maskable = false): void
{
    $img = imagecreatetruecolor($size, $size);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);

    $bg = imagecolorallocate($img, 15, 76, 92); // #0f4c5c
    $fg = imagecolorallocate($img, 241, 245, 249);
    $accent = imagecolorallocate($img, 56, 189, 248);

    $pad = $maskable ? (int) round($size * 0.12) : (int) round($size * 0.08);
    $radius = (int) round($size * 0.18);
    roundedRect($img, $pad, $pad, $size - $pad, $size - $pad, $radius, $bg);

    $cx = (int) ($size / 2);
    $cy = (int) ($size / 2);
    $font = 5;
    // Simple "AR" mark with bars
    $barW = (int) round($size * 0.08);
    $gap = (int) round($size * 0.06);
    $left = $cx - (int) round($size * 0.22);
    $top = $cy - (int) round($size * 0.2);
    $h = (int) round($size * 0.4);
    imagefilledrectangle($img, $left, $top, $left + $barW, $top + $h, $fg);
    imagefilledrectangle($img, $left + $barW + $gap, $top, $left + 2 * $barW + $gap, $top + $h, $fg);
    imagefilledrectangle($img, $left + 2 * ($barW + $gap), $top + (int) ($h * 0.35), $left + 3 * $barW + 2 * $gap, $top + $h, $accent);

    imagepng($img, $path);
    imagedestroy($img);
}

function roundedRect($img, $x1, $y1, $x2, $y2, $r, $color): void
{
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

makeIcon($dir.'/icon-192.png', 192, false);
makeIcon($dir.'/icon-512.png', 512, false);
makeIcon($dir.'/icon-192-maskable.png', 192, true);
makeIcon($dir.'/icon-512-maskable.png', 512, true);

echo "Icons generated in {$dir}\n";
