<?php

namespace App\Http\Requests\ArtistZone;

use App\Rules\LinkOnHost;
use App\Rules\PlayableMusicUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArtistStoreRequest extends FormRequest
{
    /**
     * Fields a blank form submits as empty strings rather than omitting.
     * Without this, "nullable|url" rejects an untouched optional input.
     */
    private const OPTIONAL_TEXT_FIELDS = [
        'biography', 'website', 'email', 'formed_date', 'music_url',
        'bandcamp_url', 'spotify_url', 'soundcloud_url', 'youtube_url', 'instagram_url',
    ];

    /**
     * Authorization is handled by ArtistPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (self::OPTIONAL_TEXT_FIELDS as $field) {
            if (is_string($this->input($field)) && trim($this->input($field)) === '') {
                $normalised[$field] = null;
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'artist_type' => ['required', 'string', Rule::in(array_keys(config('riff.artist.types')))],

            // Genres are ids from the curated vocabulary, never free text.
            'genres' => ['required', 'array', 'min:1', 'max:'.config('riff.artist.max_genres')],
            'genres.*' => ['integer', 'distinct', Rule::exists('genres', 'id')],

            // Public location. Only ever town-level: the geocoder turns
            // this into an approximate pin, and no street address is asked
            // for anywhere in Riff.
            'hometown' => ['required', 'string', 'max:255'],
            'stateOrProvince' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'show_on_map' => ['required', 'boolean'],
            // Who may post on this artist's wall. The artist's own call:
            // fans by default, opened to everyone or closed entirely.
            'wall_mode' => ['sometimes', Rule::in(array_keys((array) config('riff.wall.modes')))],

            'biography' => ['nullable', 'string', 'max:5000'],
            'formed_date' => ['nullable', 'date', 'before_or_equal:today'],
            'email' => ['nullable', 'email', 'max:255'],

            'website' => ['nullable', 'url:http,https', 'max:255'],
            'bandcamp_url' => ['nullable', 'url:http,https', 'max:255', new LinkOnHost(['bandcamp.com'])],
            'spotify_url' => ['nullable', 'url:http,https', 'max:255', new LinkOnHost(['spotify.com'])],
            'soundcloud_url' => ['nullable', 'url:http,https', 'max:255', new LinkOnHost(['soundcloud.com'])],
            'youtube_url' => ['nullable', 'url:http,https', 'max:255', new LinkOnHost(['youtube.com', 'youtu.be'])],
            'instagram_url' => ['nullable', 'url:http,https', 'max:255', new LinkOnHost(['instagram.com'])],

            'music_url' => ['nullable', 'string', 'max:2048', new PlayableMusicUrl],

            'photo' => ['nullable', ...ArtistPhotoRequest::photoRules()],
        ];
    }

    public function attributes(): array
    {
        return [
            'artist_type' => 'artist type',
            'stateOrProvince' => 'state or province',
            'music_url' => 'music link',
            'bandcamp_url' => 'Bandcamp link',
            'spotify_url' => 'Spotify link',
            'soundcloud_url' => 'SoundCloud link',
            'youtube_url' => 'YouTube link',
            'instagram_url' => 'Instagram link',
        ];
    }
}
