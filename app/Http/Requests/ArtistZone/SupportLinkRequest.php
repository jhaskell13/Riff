<?php

namespace App\Http\Requests\ArtistZone;

use App\Rules\SupportUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The whole support section, submitted at once. Authorization is
 * ArtistPolicy::update, applied in the controller.
 */
class SupportLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'links' => ['present', 'array', 'max:'.(int) config('riff.support.max_links')],
            'links.*' => ['array'],
            // An id the form sends is only a hint that this row already
            // existed; ArtistSupportLinks::sync ignores any id that is not
            // this artist's, so it is not an authorization boundary.
            'links.*.id' => ['nullable', 'integer'],
            'links.*.type' => ['required', 'string', Rule::in(array_keys((array) config('riff.support.types')))],
            'links.*.label' => ['nullable', 'string', 'max:'.(int) config('riff.support.max_label')],
        ];

        // The URL rule depends on the sibling type, so it is built per row
        // rather than once for links.*.url.
        foreach (array_keys((array) $this->input('links', [])) as $key) {
            $rules["links.{$key}.url"] = [
                'required',
                'string',
                'max:2048',
                new SupportUrl(is_string($type = $this->input("links.{$key}.type")) ? $type : null),
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['links' => 'support links'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'links.max' => 'You can show up to :max support links.',
            'links.*.type.required' => 'Pick what kind of link this is.',
        ];
    }
}
