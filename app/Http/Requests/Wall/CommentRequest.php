<?php

namespace App\Http\Requests\Wall;

use App\Contracts\Commentable;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CommentRequest extends FormRequest
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
            'content' => ['required', 'string', 'max:'.(int) config('riff.wall.max_comment')],
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
            'as_artist' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The thing being commented on, whichever route reached here. Keeps the
     * reply checks below identical for wall posts and show threads.
     */
    public function commentable(): (Commentable&Model)|null
    {
        foreach (['post', 'show'] as $parameter) {
            $value = $this->route($parameter);

            if ($value instanceof Commentable && $value instanceof Model) {
                return $value;
            }
        }

        return null;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $parentId = $this->input('parent_id');

            if ($parentId === null || $parentId === '') {
                return;
            }

            $parent = Comment::find($parentId);

            if ($parent === null) {
                return;
            }

            // Replies are one level deep: a reply points at a top-level
            // comment only. A wall has to read on a phone, and a flat
            // reply list renders in two queries instead of a recursive walk.
            if ($parent->parent_id !== null) {
                $validator->errors()->add('parent_id', 'You can only reply to a top-level comment.');
            }

            // And it must belong to the thread being replied to, or a
            // reply could be smuggled onto another artist's wall, or onto
            // another show's thread.
            $subject = $this->commentable();

            if ($subject === null
                || $parent->commentable_type !== $subject->getMorphClass()
                || $parent->commentable_id !== $subject->getKey()) {
                $validator->errors()->add('parent_id', 'That comment is not on this thread.');
            }
        });
    }
}
