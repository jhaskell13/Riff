<?php

namespace Database\Seeders\Support;

use App\Models\Artist;
use Illuminate\Support\Facades\Storage;

/**
 * Generated cover art for seeded artists.
 *
 * Without photos every seeded card falls back to the same grey note icon,
 * the "has a photo" filter matches nothing, and the map looks like a
 * prototype rather than a product. These are flat generated images --
 * deterministic colours from the artist's name, so a re-run produces the
 * same art -- written straight to the media disk.
 *
 * This deliberately bypasses App\Services\ArtistPhotos, the only path a
 * real upload may take. That service exists to re-encode uploads and strip
 * EXIF, and there is nothing to strip from an image generated here; what
 * must never happen is a *request* reaching storage any other way.
 */
class PlaceholderPhoto
{
    public static function available(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Draw and attach a cover for the artist. Returns false when GD is
     * missing, which leaves the profile with its placeholder icon rather
     * than failing the seed.
     */
    public static function attach(Artist $artist): bool
    {
        if (! self::available()) {
            return false;
        }

        $path = 'artists/'.$artist->getKey().'/seed.jpg';

        Storage::disk(config('riff.media.disk'))->put($path, self::draw($artist->name), 'public');

        // forceFill because image_path is not fillable: outside this
        // seeder, only the upload pipeline may write it.
        $artist->forceFill(['image_path' => $path])->save();

        return true;
    }

    /**
     * A band of colour with a few darker shapes over it. Enough to read as
     * cover art at avatar size, which is where it is mostly seen.
     */
    private static function draw(string $name): string
    {
        $size = 600;
        $seed = crc32($name);
        $image = imagecreatetruecolor($size, $size);

        // Hue from the name, kept dark enough for the white text the UI
        // draws beside it to stay readable.
        [$r, $g, $b] = self::colour($seed, 0.45);
        imagefilledrectangle($image, 0, 0, $size, $size, imagecolorallocate($image, $r, $g, $b));

        [$r2, $g2, $b2] = self::colour($seed >> 8, 0.75);
        $accent = imagecolorallocate($image, $r2, $g2, $b2);

        // Three bands at an angle, offset by the seed, so no two acts share
        // a cover at a glance.
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
