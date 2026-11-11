<?php

namespace App\Http\Resources;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The feed payload: pins exactly what the client sees so internal
 * columns (status, counters, meta_data) never leak by default.
 *
 * @mixin Post
 */
class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'published_at' => $this->published_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'artist' => $this->whenLoaded('artist', fn () => $this->artist === null ? null : [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
                'image_url' => $this->artist->image_url,
                'url' => route('artists.show', $this->artist),
            ]),
        ];
    }
}
