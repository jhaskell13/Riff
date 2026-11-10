<?php

namespace App\Http\Requests\Moderation;

use App\Support\ModerationAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A moderator's decision. Authorization lives in ReportPolicy::resolve,
 * checked by the controller.
 */
class ReportResolveRequest extends FormRequest
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
        return [
            'action' => ['required', Rule::enum(ModerationAction::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * An action has to make sense for what was reported: a post cannot be
     * taken off the map, and an artist is not a post to remove.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $action = ModerationAction::tryFrom((string) $this->input('action'));
                $subject = $this->route('report')?->reportable;

                if ($action === null || $subject === null) {
                    return;
                }

                if (! $action->appliesTo($subject)) {
                    $validator->errors()->add('action', 'That action does not apply to this report.');
                }
            },
        ];
    }

    public function action(): ModerationAction
    {
        return ModerationAction::from($this->validated('action'));
    }
}
