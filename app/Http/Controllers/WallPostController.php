<?php

namespace App\Http\Controllers;

use App\Http\Requests\Wall\WallPostRequest;
use App\Models\Artist;
use App\Models\Post;
use App\Services\Wall;
use Illuminate\Http\RedirectResponse;

/**
 * Posting on an artist's page -- the surface Phase 3 is built around.
 *
 * Two voices, one endpoint: members post as the artist, fans post as
 * themselves, and which one you get is decided by authorization, never by
 * the request (App\Services\Wall::voiceFor).
 */
class WallPostController extends Controller
{
    public function __construct(private Wall $wall) {}

    public function store(WallPostRequest $request, Artist $artist): RedirectResponse
    {
        $asArtist = (bool) $request->validated('as_artist', false);

        // Two different permissions, checked against what is being asked
        // for. Asking to be the band without membership is refused outright
        // rather than quietly downgraded: someone who ticked "post as the
        // band" and got a post under their own name would be surprised in
        // the worst way -- in public, on someone else's page.
        $this->authorize($asArtist ? 'postAsArtist' : 'postOnWall', $artist);

        $this->wall->post(
            $request->user(),
            $artist,
            (string) $request->validated('content'),
            $asArtist,
        );

        return back();
    }

    /**
     * Delete your own words. An artist taking a *fan's* post off their wall
     * is hiding, not deleting, and is artist-level moderation (step 4).
     */
    public function destroy(Post $post): RedirectResponse
    {
        $this->authorize('delete', $post);

        $post->delete();

        return back();
    }
}
