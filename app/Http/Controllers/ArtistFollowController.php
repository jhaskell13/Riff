<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Notifications\NewFollower;
use App\Services\FanCounts;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Following is the first commitment a listener makes after a discovery, so
 * it has to be cheap and repeatable: both actions are idempotent and return
 * to wherever the listener pressed the button -- a map card or a profile.
 */
class ArtistFollowController extends Controller
{
    public function __construct(
        private FanCounts $fanCounts,
        private Notifier $notifier,
    ) {}

    public function store(Request $request, Artist $artist): RedirectResponse
    {
        $this->authorize('follow', $artist);

        $alreadyAFan = $request->user()->follows($artist);

        $request->user()->followedArtists()->syncWithoutDetaching([$artist->id]);
        $this->fanCounts->forget($artist);

        // Only on the way in: following is idempotent, and re-pressing the
        // button should not tell the band they gained a fan twice.
        if (! $alreadyAFan) {
            $this->notifier->send(
                $artist->members()->get(),
                (new NewFollower($artist, $request->user()->name))->causedBy($request->user()),
            );
        }

        return back();
    }

    /**
     * Mute or unmute one artist without unfollowing them.
     *
     * The dial belongs on the follow, not the account: following a band
     * you love should not mean the same volume as following one you are
     * curious about, and the alternative -- unfollowing to get quiet --
     * loses them from your scene as well.
     */
    public function notify(Request $request, Artist $artist): RedirectResponse
    {
        $this->authorize('follow', $artist);

        $validated = $request->validate(['notify' => ['required', 'boolean']]);

        // updateExistingPivot, not sync: someone who is not following has
        // nothing to mute, and this must never create a follow as a side
        // effect of a settings toggle.
        $request->user()->followedArtists()->updateExistingPivot(
            $artist->id,
            ['notify' => $validated['notify']],
        );

        return back();
    }

    public function destroy(Request $request, Artist $artist): RedirectResponse
    {
        $this->authorize('follow', $artist);

        $request->user()->followedArtists()->detach($artist->id);
        $this->fanCounts->forget($artist);

        return back();
    }
}
