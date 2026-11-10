<?php

namespace App\Http\Requests\Moderation;

use App\Models\Artist;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A report names its subject by kind and id rather than by route, so one
 * endpoint serves every surface that can raise one. Authorization is the
 * policy's job (see ReportPolicy); this only validates.
 */
class ReportStoreRequest extends FormRequest
{
    /**
     * The kinds of thing that can be reported, and the model behind each.
     * Adding a subject here is all a new report surface needs.
     */
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
     * Existence is checked here rather than with an exists rule because the
     * table depends on the type, and an artist archived between the page
     * loading and the report being sent should read as "nothing to report"
     * instead of a 404 page.
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

    /**
     * The model being reported. Archived artists and already-removed posts
     * are excluded by the default scopes, which is right: there is nothing
     * left to act on.
     */
    public function subject(): Artist|Post
    {
        $model = self::SUBJECTS[$this->validated('type')];

        return $model::findOrFail($this->validated('id'));
    }
}
