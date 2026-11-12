<?php

namespace App\Http\Requests\Listings;

use App\Models\Listing;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Posting and editing a listing. Which artist (if any) it is posted as is
 * checked here for shape and in the controller for membership.
 */
class ListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['artist_id', 'country'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = Listing::typeConfig((string) $this->input('type'));
        $asArtist = $type['as_artist'] ?? 'optional';

        return [
            'type' => ['required', 'string', Rule::in(array_keys(config('riff.listings.types')))],
            'artist_id' => match ($asArtist) {
                'required' => ['required', 'integer', Rule::exists('artists', 'id')->whereNull('deleted_at')],
                'never' => ['prohibited'],
                default => ['nullable', 'integer', Rule::exists('artists', 'id')->whereNull('deleted_at')],
            },
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'body' => ['required', 'string', 'min:10', 'max:'.config('riff.listings.max_body')],
            'instruments' => ['nullable', 'array', 'max:6'],
            'instruments.*' => ['string', 'distinct', Rule::in(array_keys(config('riff.listings.instruments')))],
            'genres' => ['nullable', 'array', 'max:3'],
            'genres.*' => ['integer', 'distinct', Rule::exists('genres', 'id')],
            // Public, town-level: where the band is, or where the musician
            // would travel from.
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $listing = $this->route('listing');

                // The kind of post can't change underneath its replies.
                if ($listing instanceof Listing && $this->input('type') !== $listing->type) {
                    $validator->errors()->add('type', "A listing's type can't be changed; close it and post a new one.");
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function listingAttributes(): array
    {
        return $this->safe()->only(['type', 'title', 'body', 'instruments', 'city', 'state', 'country']);
    }

    public function attributes(): array
    {
        return ['artist_id' => 'artist', 'body' => 'description'];
    }

    public function messages(): array
    {
        return [
            'artist_id.required' => 'Choose which of your artists this is for.',
            'artist_id.prohibited' => 'This kind of listing is posted as yourself, not as an artist.',
        ];
    }
}
