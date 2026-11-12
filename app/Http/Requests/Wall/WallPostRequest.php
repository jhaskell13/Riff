<?php

namespace App\Http\Requests\Wall;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A post on an artist's wall. Authorization is the controller's job
 * (ArtistPolicy::postAsArtist / postOnWall).
 *
 * Note what is *not* here: author_context. The voice is decided by the
 * authorization result, never by the request -- a validated "as" field
 * would be an endpoint for speaking as somebody else.
 */
class WallPostRequest extends FormRequest
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
            'content' => ['required', 'string', 'max:'.(int) config('riff.wall.max_post')],
            // Which voice the poster is *asking* for. Not the voice itself:
            // the controller grants artist voice only to members and falls
            // back to the fan voice otherwise.
            'as_artist' => ['sometimes', 'boolean'],
        ];
    }
}
