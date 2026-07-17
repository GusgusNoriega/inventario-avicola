<?php

declare(strict_types=1);

function createIcon(string $path, int $size, bool $maskable = false): void
{
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    $background = imagecolorallocate($image, 18, 56, 47);
    $accent = imagecolorallocate($image, 244, 184, 67);
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefill($image, 0, 0, $background);

    $padding = (int) round($size * ($maskable ? 0.20 : 0.12));
    $center = intdiv($size, 2);
    $radius = intdiv($size - ($padding * 2), 2);
    imagefilledellipse($image, $center, $center, $radius * 2, $radius * 2, $accent);

    $bodyX = (int) round($size * 0.47);
    $bodyY = (int) round($size * 0.53);
    imagefilledellipse($image, $bodyX, $bodyY, (int) round($size * 0.39), (int) round($size * 0.31), $white);
    imagefilledellipse($image, (int) round($size * 0.61), (int) round($size * 0.40), (int) round($size * 0.20), (int) round($size * 0.20), $white);

    $beak = [
        (int) round($size * 0.70), (int) round($size * 0.39),
        (int) round($size * 0.79), (int) round($size * 0.43),
        (int) round($size * 0.70), (int) round($size * 0.47),
    ];
    imagefilledpolygon($image, $beak, $accent);
    imagefilledellipse($image, (int) round($size * 0.64), (int) round($size * 0.38), max(3, intdiv($size, 42)), max(3, intdiv($size, 42)), $background);

    $stroke = max(3, intdiv($size, 42));
    imagesetthickness($image, $stroke);
    imageline($image, (int) round($size * 0.42), (int) round($size * 0.67), (int) round($size * 0.39), (int) round($size * 0.76), $white);
    imageline($image, (int) round($size * 0.54), (int) round($size * 0.67), (int) round($size * 0.57), (int) round($size * 0.76), $white);

    imagepng($image, $path, 9);
    imagedestroy($image);
}

$iconDirectory = dirname(__DIR__).'/public/icons';
if (!is_dir($iconDirectory) && !mkdir($iconDirectory, 0775, true) && !is_dir($iconDirectory)) {
    throw new RuntimeException('No fue posible crear el directorio de iconos.');
}

createIcon($iconDirectory.'/icon-192.png', 192);
createIcon($iconDirectory.'/icon-512.png', 512);
createIcon($iconDirectory.'/icon-maskable-512.png', 512, true);

echo "Iconos PWA generados.\n";
