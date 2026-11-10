<?php

namespace App\Http\Requests\Moderation;

use App\Models\Artist;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A report names its subject by kind and id. Authorization is ReportPolicy's
 * job; this only validates.
 */
class ReportStoreRequest extends FormRequest
{
    /** What can be reported; adding a kind here is all a new surface needs. */
    public const SUBJECTS = [
        'artist' => Artist::class,
        'post' => Post::class,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(self::SUBJECTS))],
            'id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(array_keys(config('riff.moderation.reasons')))],
            'details' => ['nullable', 'string', 'max:'.config('riff.moderation.max_details')],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.in' => 'Choose one of the listed reasons.',
        ];
    }

    /**
     * The table depends on the type, so existence can't be an exists rule;
     * and a just-archived subject should validate out, not 404.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $model = self::SUBJECTS[$this->input('type')];

                if (! $model::whereKey($this->input('id'))->exists()) {
                    $validator->errors()->add('id', 'This is no longer on Riff.');
                }
            },
        ];
    }

    /** The model being reported; default scopes exclude archived subjects. */
    public function subject(): Artist|Post
    {
        $model = self::SUBJECTS[$this->validated('type')];

        return $model::findOrFail($this->validated('id'));
    }
}
