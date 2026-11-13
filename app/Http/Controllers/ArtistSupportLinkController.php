<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtistZone\SupportLinkRequest;
use App\Models\Artist;
use App\Models\ArtistSupportLink;
use App\Services\ArtistSupportLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * The "Support this artist" section: typed links to where the artist
 * already gets paid, and a bare counter of outbound clicks.
 *
 * Riff handles no money here and takes no cut (ROADMAP principle 4). The
 * only thing this controller knows about a sale is that someone left for
 * the place it might happen.
 */
class ArtistSupportLinkController extends Controller
{
    public function __construct(private ArtistSupportLinks $links) {}

    /**
     * Replace the whole section. Members only -- the same authority that
     * edits the profile.
     */
    public function update(SupportLinkRequest $request, Artist $artist): RedirectResponse
    {
        $this->authorize('update', $artist);

        /** @var array<int|string, array{id?: int|null, type: string, url: string, label?: string|null}> $links */
        $links = $request->validated('links', []);

        $this->links->sync($artist, $links);

        return back();
    }

    /**
     * Count an outbound click, then get out of the way.
     *
     * Public and unauthenticated: a support link works for a guest, which
     * is the whole point of a shareable artist page. 204 with no body, so
     * the client can fire it as a beacon and navigate regardless -- a
     * blocked or failed beacon must never cost the artist a sale.
     */
    public function click(Artist $artist, ArtistSupportLink $link): Response
    {
        // Scoped by hand rather than by scoped route binding, so a link id
        // from one artist cannot be counted against another's.
        abort_if($link->artist_id !== $artist->id, 404);

        $this->links->recordClick($link);

        return response()->noContent();
    }
}
