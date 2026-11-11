<?php

namespace Database\Seeders\Support;

use App\Models\Artist;
use Illuminate\Support\Facades\Storage;

/**
 * Generated cover art for seeded artists: deterministic colours from the
 * name, written straight to the media disk. Deliberately bypasses
 * ArtistPhotos -- there is no EXIF to strip from a generated image; the
 * invariant is that no *request* reaches storage any other way.
 */
class PlaceholderPhoto
{
    public static function available(): bool
    {
        return extension_loaded('gd');
    }

    /** Draw and attach a cover; false (not failure) when GD is missing. */
    public static function attach(Artist $artist): bool
    {
        if (! self::available()) {
            return false;
        }

        $path = 'artists/'.$artist->getKey().'/seed.jpg';

        Storage::disk(config('riff.media.disk'))->put($path, self::draw($artist->name), 'public');

        $artist->forceFill(['image_path' => $path])->save();

        return true;
    }

    /** Colour and a few angled bands: reads as cover art at avatar size. */
    private static function draw(string $name): string
    {
        $size = 600;
        $seed = crc32($name);
        $image = imagecreatetruecolor($size, $size);

        [$r, $g, $b] = self::colour($seed, 0.45);
        imagefilledrectangle($image, 0, 0, $size, $size, imagecolorallocate($image, $r, $g, $b));

        [$r2, $g2, $b2] = self::colour($seed >> 8, 0.75);
        $accent = imagecolorallocate($image, $r2, $g2, $b2);

        for ($i = 0; $i < 3; $i++) {
            $offset = (int) (($seed >> (4 * $i)) % $size);
            imagefilledpolygon($image, [
                0, $offset,
                $size, $offset - 140,
                $size, $offset - 80,
                0, $offset + 60,
            ], $accent);
        }

        ob_start();
        imagejpeg($image, null, 82);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function colour(int $seed, float $brightness): array
    {
        $hue = $seed % 360;
        $base = [
            (int) (127 + 127 * cos(deg2rad($hue))),
            (int) (127 + 127 * cos(deg2rad($hue - 120))),
            (int) (127 + 127 * cos(deg2rad($hue + 120))),
        ];

        return array_map(fn (int $channel) => (int) max(0, min(255, $channel * $brightness)), $base);
    }
}
