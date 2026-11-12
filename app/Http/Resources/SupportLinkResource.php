<?php

namespace App\Http\Resources;

use App\Models\ArtistSupportLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One support link as the public page sees it. The click count is pinned
 * behind $withClicks: it is the artist's own number, shown in the Artist
 * Zone, and publishing it would turn a support section into a popularity
 * ranking -- which ROADMAP principle 2 rules out.
 *
 * @mixin ArtistSupportLink
 */
class SupportLinkResource extends JsonResource
{
    private bool $withClicks = false;

    /** Include the click count, for the artist's own members. */
    public function withClicks(bool $include = true): static
    {
        $this->withClicks = $include;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'typeLabel' => $this->type_label,
            'label' => $this->label,
            // What the button says: the artist's own wording where they
            // gave one, else the type's.
            'displayLabel' => $this->display_label,
            'icon' => $this->icon,
            'url' => $this->url,
            // Shown beside the link so nobody clicks blind.
            'host' => $this->host,
            'clickUrl' => route('artists.support.click', [
                'artist' => $this->artist_id,
                'link' => $this->id,
            ]),
            ...$this->withClicks ? ['clicks' => $this->clicks] : [],
        ];
    }
}
