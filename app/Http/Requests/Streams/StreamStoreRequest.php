<?php

namespace App\Http\Requests\Streams;

use App\Models\Artist;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StreamStoreRequest extends FormRequest
{
    /**
     * Artist membership is authorized against LiveStreamPolicy in the
     * controller, after the artist_id has been validated to exist.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'artist_id' => ['required', 'integer', Rule::exists(Artist::class, 'id')->withoutTrashed()],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
