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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * "Your scene": the signed-in home.
 *
 * What the artists you follow have said and when they next play, plus what
 * is coming up around you. **Ordered by date and distance, never by
 * engagement** (ROADMAP principle 2) — a new band's first show is as
 * visible here as a popular one's.
 *
 * Composed from the map's own searches for the "around you" half, exactly
 * as SceneView does, so this page and the map can never disagree about
 * what "upcoming", "active" or "blocked" mean.
 *
 * **Deliberately uncached.** It is per-viewer by definition, so it follows
 * the MapFilters::hiddenFor rule: personal views are not cached, they are
 * *bounded*. Every section here has a hard limit and the updates paginate.
 */
class YourScene
{
    public function __construct(private SceneMapSearch $scene) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, int $page = 1): array
    {
        $followed = $user->followedArtists()->pluck('artists.id')->all();
        $unit = $user->distance_unit ?: (string) config('riff.distance.default_unit');

        return [
            'updates' => $this->updates($followed, $page),
            'shows' => $this->showsFromFollowed($followed),
            'nearby' => $this->nearby($user, $unit),
            'followingCount' => count($followed),
            // Where "around you" means. Null when the account has never
            // been placed -- the page then asks for a town rather than
            // silently showing an empty half.
            'area' => $this->areaFor($user),
        ];
    }

    /**
     * "Hartford, CT", or null when the account has never been placed --
     * half this page is about a place, and without one it says so rather
     * than rendering an empty heading.
     */
    private function areaFor(User $user): ?string
    {
        if ($user->lat === null || $user->lng === null) {
            return null;
        }

        $area = trim(collect([$user->city, $user->state])->filter()->implode(', '));

        return $area === '' ? null : $area;
    }

    /**
     * What the artists you follow have said.
     *
     * **Artist-voice posts only.** A wall also carries what fans wrote
     * there, and pulling that in would turn a page about the bands you
     * chose into a stream of strangers talking -- which is the global feed
     * this page replaces.
     *
     * @param  list<int>  $followed
     * @return array<string, mixed>
     */
    private function updates(array $followed, int $page): array
    {
        if ($followed === []) {
            return ['data' => [], 'nextPage' => null, 'total' => 0];
        }

        $posts = Post::query()
            ->whereIn('artist_id', $followed)
            ->where('author_context', AuthorContext::Artist->value)
            ->whereIn('status', Post::visibleStatuses(false))
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->with(['artist:id,name,slug,image_path'])
            ->withCount(['comments' => fn ($query) => $query
                ->whereNull('parent_id')
                ->whereIn('status', Post::visibleStatuses(false))])
            ->latest('published_at')
            ->latest('id')
            ->paginate((int) config('riff.scene_home.updates_per_page'), ['*'], 'page', $page);

        return [
            'data' => $posts->getCollection()->map(fn (Post $post) => [
                'id' => $post->id,
                'content' => $post->content,
                'publishedAt' => $post->published_at?->toIso8601String(),
                'commentsCount' => (int) $post->comments_count,
                'likesCount' => (int) $post->likes,
                'artist' => [
                    'id' => $post->artist?->id,
                    'name' => $post->artist?->name,
                    'imageUrl' => $post->artist?->image_url,
                    'url' => $post->artist === null ? null : route('artists.show', $post->artist),
                ],
            ])->values()->all(),
            'nextPage' => $posts->hasMorePages() ? $posts->currentPage() + 1 : null,
            'total' => $posts->total(),
        ];
    }

    /**
     * When the artists you follow next play. Soonest first -- the point of
     * following a band is not missing them.
     *
     * Uses the confirmed lineup only, like every public surface: a pending
     * invitation is not a date anyone should plan around.
     *
     * @param  list<int>  $followed
     * @return EloquentCollection<int, Show>
     */
    private function showsFromFollowed(array $followed): EloquentCollection
    {
        if ($followed === []) {
            return new EloquentCollection;
        }

        return Show::query()
            ->listed()
            ->upcoming()
            ->whereHas('lineup', fn ($query) => $query
                ->whereIn('artist_id', $followed)
                ->where('status', LineupEntry::CONFIRMED))
            ->with(['confirmedLineup.artist', 'venue'])
            ->limit((int) config('riff.scene_home.max_shows'))
            ->get();
    }

    /**
     * What is on around you, whoever is playing it -- the half of the page
     * that is about a place rather than a follow list. Same searches the
     * map runs, so "upcoming" and "blocked" mean one thing.
     *
     * @return array<string, mixed>
     */
    private function nearby(User $user, string $unit): array
    {
        if ($user->lat === null || $user->lng === null) {
            return ['shows' => [], 'listings' => []];
        }

        $filters = fn (int $limit) => new SceneFilters(
            bounds: null,
            origin: new MapPoint(round((float) $user->lat, 3), round((float) $user->lng, 3)),
            radiusKm: (float) config('riff.scenes.radius_km'),
            unit: $unit,
            window: ShowWindow::make('month', (string) config('riff.map.default_timezone')),
            genreIds: [],
            limit: $limit,
            // Personal, so it is not cached -- see the class comment.
            hiddenFor: $user->hasBlocks() ? $user->id : null,
        );

        return [
            'shows' => $this->scene->shows($filters((int) config('riff.scene_home.max_nearby_shows')))['data'],
            'listings' => $this->scene->listings($filters((int) config('riff.scene_home.max_listings')))['data'],
        ];
    }
}
