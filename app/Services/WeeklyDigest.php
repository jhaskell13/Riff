<?php

namespace App\Services;

use App\DTO\MapPoint;
use App\DTO\SceneFilters;
use App\Models\LineupEntry;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use App\Services\Map\SceneMapSearch;
use App\Support\AuthorContext;
use App\Support\ShowWindow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * "Your scene, by email."
 *
 * Four sections, in the order someone actually cares about them:
 *
 *  1. **Shows you said you were going to**, soonest first. The only thing
 *     here you have already committed to, so it goes first — a digest that
 *     buries your own Friday night under a band's news is a digest you
 *     stop opening.
 *  2. **When the artists you follow next play.**
 *  3. **What they said this week.**
 *  4. **What is coming up around you**, whoever is playing it.
 *
 * Composed from the same searches the map and "your scene" use, so an
 * email and the site can never disagree about "upcoming" or "blocked".
 *
 * **Nothing is sent when there is nothing to say.** An empty digest is the
 * fastest way to teach someone to unsubscribe, so `hasAnything()` is the
 * gate the sender checks before writing at all.
 */
class WeeklyDigest
{
    public function __construct(private SceneMapSearch $scene) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $since = $this->windowStart($user);
        $followed = $user->followedArtists()->pluck('artists.id')->all();

        return [
            'since' => $since,
            'myShows' => $this->myShows($user),
            'followedShows' => $this->followedShows($followed),
            'updates' => $this->updates($followed, $since),
            'nearby' => $this->nearby($user),
        ];
    }

    /**
     * Whether this digest is worth an email at all.
     *
     * @param  array<string, mixed>  $digest
     */
    public function hasAnything(array $digest): bool
    {
        return $digest['myShows']->isNotEmpty()
            || $digest['followedShows']->isNotEmpty()
            || $digest['updates']->isNotEmpty()
            || $digest['nearby']['shows'] !== []
            || $digest['nearby']['listings'] !== [];
    }

    /**
     * What has happened since this person was last written to -- or, for a
     * first digest, the configured look-back. Without the second case a
     * new account's first email would carry everything ever posted.
     */
    private function windowStart(User $user): Carbon
    {
        return $user->digest_sent_at?->copy()
            ?? now()->subDays((int) config('riff.digest.first_window_days'));
    }

    /**
     * Shows this person marked going or interested, still to come.
     *
     * Deliberately first in the email: it is the only section about
     * something they have already decided to do.
     *
     * @return Collection<int, Show>
     */
    private function myShows(User $user)
    {
        return Show::query()
            ->listed()
            ->upcoming()
            ->whereHas('attendees', fn ($query) => $query->whereKey($user->id))
            ->with(['confirmedLineup.artist', 'venue'])
            ->limit((int) config('riff.digest.max_my_shows'))
            ->get();
    }

    /**
     * @param  list<int>  $followed
     * @return Collection<int, Show>
     */
    private function followedShows(array $followed)
    {
        if ($followed === []) {
            return Show::query()->whereRaw('1 = 0')->get();
        }

        return Show::query()
            ->listed()
            ->upcoming()
            ->whereHas('lineup', fn ($query) => $query
                ->whereIn('artist_id', $followed)
                ->where('status', LineupEntry::CONFIRMED))
            ->with(['confirmedLineup.artist', 'venue'])
            ->limit((int) config('riff.digest.max_shows'))
            ->get();
    }

    /**
     * What the artists you follow said since the last digest.
     *
     * Artist-voice only, for the same reason "your scene" is: a wall also
     * carries what fans wrote there, and mailing that to everyone would be
     * a stream of strangers in an inbox.
     *
     * @param  list<int>  $followed
     * @return Collection<int, Post>
     */
    private function updates(array $followed, Carbon $since)
    {
        if ($followed === []) {
            return Post::query()->whereRaw('1 = 0')->get();
        }

        return Post::query()
            ->whereIn('artist_id', $followed)
            ->where('author_context', AuthorContext::Artist->value)
            ->whereIn('status', Post::visibleStatuses(false))
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            // Bound as a string: an immutable Carbon can bind as ISO-8601,
            // which SQLite compares as text. See CLAUDE.md.
            ->where('published_at', '>=', $since->toDateTimeString())
            ->with(['artist:id,name,slug,image_path'])
            ->latest('published_at')
            ->limit((int) config('riff.digest.max_updates'))
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function nearby(User $user): array
    {
        if ($user->lat === null || $user->lng === null) {
            return ['shows' => [], 'listings' => []];
        }

        $filters = fn (int $limit) => new SceneFilters(
            bounds: null,
            origin: new MapPoint(round((float) $user->lat, 3), round((float) $user->lng, 3)),
            radiusKm: (float) config('riff.scenes.radius_km'),
            unit: $user->distance_unit ?: (string) config('riff.distance.default_unit'),
            window: ShowWindow::make('month', (string) config('riff.map.default_timezone')),
            genreIds: [],
            limit: $limit,
            hiddenFor: $user->hasBlocks() ? $user->id : null,
        );

        return [
            'shows' => $this->scene->shows($filters((int) config('riff.digest.max_nearby')))['data'],
            'listings' => $this->scene->listings($filters((int) config('riff.digest.max_listings')))['data'],
        ];
    }
}
