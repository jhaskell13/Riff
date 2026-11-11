<?php

namespace App\Http\Requests\Venues;

use App\Models\Venue;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creating and editing a venue share one set of rules, so the two forms
 * cannot drift. Authorization is VenuePolicy's, in the controller.
 */
class VenueRequest extends FormRequest
{
    private const OPTIONAL_TEXT_FIELDS = ['street_address', 'country', 'age_policy', 'website', 'description'];

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

        // A private venue never carries a street, even if the form still
        // held one from before the toggle. The model enforces this too.
        if ($this->input('address_visibility') === Venue::VISIBILITY_PRIVATE) {
            $normalised['street_address'] = null;
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', Rule::in(array_keys(config('riff.venues.kinds')))],
            'address_visibility' => ['required', 'string', Rule::in(Venue::VISIBILITIES)],
            // A public venue is pinned at its door, so it needs one.
            'street_address' => [
                Rule::requiredIf($this->input('address_visibility') === Venue::VISIBILITY_PUBLIC),
                'nullable', 'string', 'max:255',
            ],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'age_policy' => ['nullable', 'string', Rule::in(array_keys(config('riff.venues.age_policies')))],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * One venue per name per town. Community submission makes duplicates
     * the likeliest mess, and shows (Step 3) need one row to point at.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $current = $this->route('venue');

                $duplicate = Venue::query()
                    ->whereRaw('lower(name) = ?', [Str::lower(trim((string) $this->input('name')))])
                    ->whereRaw('lower(city) = ?', [Str::lower(trim((string) $this->input('city')))])
                    ->whereRaw('lower(state) = ?', [Str::lower(trim((string) $this->input('state')))])
                    ->when($current instanceof Venue, fn ($query) => $query->whereKeyNot($current->getKey()))
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add('name', 'This venue is already on Riff in that town.');
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'address_visibility' => 'address visibility',
            'street_address' => 'street address',
            'age_policy' => 'age policy',
        ];
    }

    public function messages(): array
    {
        return [
            'street_address.required' => 'A public venue needs a street address. If the address should stay private, choose "Share the address privately".',
        ];
    }
}
