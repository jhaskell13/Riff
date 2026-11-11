<?php

namespace App\Http\Requests\ArtistZone;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArtistPhotoRequest extends FormRequest
{
    /**
     * Authorization is handled by ArtistPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['photo' => ['required', ...self::photoRules()]];
    }

    /**
     * Shared with artist creation, where the photo is optional.
     *
     * The dimension check reads only the image header, so an oversized
     * source is rejected before anything decodes it.
     *
     * @return list<mixed>
     */
    public static function photoRules(): array
    {
        $config = config('riff.media.artist_photo');

        return [
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:'.$config['max_kilobytes'],
            Rule::dimensions()
                ->maxWidth($config['max_source_edge_pixels'])
                ->maxHeight($config['max_source_edge_pixels']),
        ];
    }

    public function messages(): array
    {
        return [
            'photo.dimensions' => 'That image is too large; use one under '
                .config('riff.media.artist_photo.max_source_edge_pixels').' pixels on each side.',
        ];
    }
}
