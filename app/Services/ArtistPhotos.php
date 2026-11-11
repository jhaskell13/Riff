<?php

namespace App\Services;

use App\Models\Artist;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * The only path by which an artist photo reaches storage.
 *
 * Every upload is decoded and re-encoded, never stored as received. That is
 * what strips EXIF: a phone photo carries the GPS coordinates of where it
 * was taken -- usually the artist's home -- and Riff promises never to
 * publish more than a town. Re-encoding also normalises format and size
 * and discards anything smuggled into the file alongside the pixels.
 */
class ArtistPhotos
{
    public function store(Artist $artist, UploadedFile $file): void
    {
        $encoded = $this->process($file);

        $disk = $this->disk();
        // A fresh name per upload, so a replaced photo is never served
        // from a stale cache under the same URL.
        $path = 'artists/'.$artist->getKey().'/'.Str::uuid().'.jpg';
        $previous = $artist->image_path;

        $disk->put($path, $encoded, 'public');

        try {
            $artist->forceFill(['image_path' => $path])->save();
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        // Only after the record points at the new file is the old one
        // removed, so a profile never references a missing image.
        if ($previous !== null && $previous !== $path) {
            $disk->delete($previous);
        }
    }

    public function remove(Artist $artist): void
    {
        $previous = $artist->image_path;

        if ($previous === null) {
            return;
        }

        $artist->forceFill(['image_path' => null])->save();
        $this->disk()->delete($previous);
    }

    /**
     * Decode, orient, bound, and re-encode as JPEG. Returns the encoded
     * bytes; nothing from the source file survives except the pixels.
     */
    private function process(UploadedFile $file): string
    {
        $config = config('riff.media.artist_photo');

        $images = new ImageManager(
            new Driver,
            // Apply the EXIF rotation to the pixels before metadata is
            // discarded, or portrait phone photos would come out sideways.
            autoOrientation: true,
            // First frame only; an animated file cannot multiply the
            // decode cost.
            decodeAnimation: false,
            strip: true,
        );

        return (string) $images
            ->decodePath($file->getRealPath())
            ->scaleDown($config['max_edge_pixels'], $config['max_edge_pixels'])
            ->encode(new JpegEncoder(quality: $config['jpeg_quality'], strip: true));
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('riff.media.disk'));
    }
}
