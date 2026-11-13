<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtistZone\ArtistStoreRequest;
use App\Http\Requests\ArtistZone\ArtistUpdateRequest;
use App\Http\Resources\ShowCardResource;
use App\Http\Resources\SupportLinkResource;
use App\Models\Artist;
use App\Models\ArtistSupportLink;
use App\Models\Genre;
use App\Models\LineupEntry;
use App\Models\Listing;
use App\Models\Post;
use App\Models\Show;
use App\Models\Venue;
use App\Services\ArtistPhotos;
use App\Services\FanCounts;
use App\Services\WallReader;
use App\Support\SceneLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ArtistZoneController extends Controller
{
    public function __construct(private WallReader $wallReader) {}

    public function index(Request $request): Response
    {
        $columns = ['artists.id', 'artists.name', 'artists.slug', 'artists.artist_type', 'artists.hometown', 'artists.stateOrProvince', 'artists.country', 'artists.image_path'];

        $artists = Auth::user()
            ->artists()
            ->select($columns)
            ->with(['members:id,name', 'genres:id,name'])
            ->withCount('followers')
            ->get()
            ->each->append(['address', 'type_label']);

        $archivedArtists = Auth::user()
            ->artists()
            ->onlyTrashed()
            ->select($columns)
            ->with('genres:id,name')
            ->get()
            ->each->append(['address', 'type_label']);

        return Inertia::render('ArtistZone/Index', [
            'artists' => $artists,
            'archivedArtists' => $archivedArtists,
            // Other bands' bills waiting on one of this user's artists.
            'showInvites' => LineupEntry::query()
                ->whereIn('artist_id', $artists->pluck('id'))
                ->where('status', LineupEntry::PENDING)
                ->whereHas('show', fn ($show) => $show->listed()->notOver())
                ->with(['artist:id,name', 'show.confirmedLineup.artist', 'show.venue', 'show.hostArtist:id,name'])
                ->get()
                ->map(fn (LineupEntry $entry) => [
                    'id' => $entry->id,
                    'artistName' => $entry->artist?->name,
                    'hostName' => $entry->show?->hostArtist?->name,
                    'show' => ShowCardResource::make($entry->show)->resolve($request),
                ])
                ->values(),
            // Shows this user's artists host, to manage.
            'hostedShows' => ShowCardResource::collection(
                Show::query()
                    ->whereIn('host_artist_id', $artists->pluck('id'))
                    ->listed()
                    ->upcoming()
                    ->with(['confirmedLineup.artist', 'venue'])
                    ->get()
            )->resolve($request),
            // Every listing this user manages, open or ended, newest first;
            // ended ones can be renewed from here.
            'listings' => Listing::query()
                ->managedBy(Auth::user())
                ->whereNull('closed_at')
                // Ended ones stay renewable here for a month, then drop off
                // (their pages remain), so the list doesn't grow forever.
                ->where('expires_at', '>', now()->subDays(30)->toDateTimeString())
                ->with('artist:id,name')
                ->latest()
                ->get()
                ->map(fn (Listing $listing) => [
                    'id' => $listing->id,
                    'title' => $listing->title,
                    'typeLabel' => $listing->type_label,
                    'icon' => $listing->icon,
                    'postedAs' => $listing->artist?->name,
                    'isActive' => $listing->is_active,
                    'canRenew' => $listing->can_renew,
                    'expiresLabel' => $listing->expires_at->isFuture()
                        ? 'Open until '.$listing->expires_at->isoFormat('MMM D')
                        : 'Ended '.$listing->expires_at->diffForHumans(),
                    'url' => route('listings.show', $listing),
                ]),
            'venues' => Auth::user()
                ->submittedVenues()
                ->orderBy('name')
                ->get()
                ->map(fn (Venue $venue) => [
                    'id' => $venue->id,
                    'slug' => $venue->slug,
                    'name' => $venue->name,
                    'kindLabel' => $venue->kind_label,
                    'area' => $venue->area,
                ]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Artist::class);

        return Inertia::render('ArtistZone/Create', $this->formOptions());
    }

    public function store(ArtistStoreRequest $request, ArtistPhotos $photos): RedirectResponse
    {
        $this->authorize('create', Artist::class);

        $validated = $request->validated();

        $artist = Artist::create(Arr::except($validated, ['genres', 'photo']));
        $artist->genres()->sync($validated['genres']);
        $artist->members()->attach($request->user());

        if ($request->hasFile('photo')) {
            $photos->store($artist, $request->file('photo'));
        }

        return to_route('artists.show', $artist);
    }

    /**
     * The public profile. Guests land here from shared links and the map,
     * so every account-specific prop must survive a null user.
     */
    public function show(Request $request, Artist $artist, FanCounts $fanCounts): Response|RedirectResponse
    {
        $this->authorize('view', $artist);

        // A historical slug resolves (Artist::resolveRouteBinding) but the
        // canonical URL is the current one.
        $requested = $request->route()->originalParameters()['artist'] ?? null;

        if ($requested !== null && $requested !== $artist->slug) {
            return redirect()->route('artists.show', $artist, 301);
        }

        $user = Auth::user();

        // Membership, following and blocking each answer several props,
        // and each is an EXISTS. Asked once here and reused below: the
        // gate was being asked the same question eight times for one
        // member's page view.
        $isMember = $user?->isMemberOf($artist) ?? false;
        $isFollowing = $user?->follows($artist) ?? false;
        $isBlocked = $user?->blocks($artist) ?? false;

        return Inertia::render('ArtistZone/Show', [
            'artist' => tap($artist
                ->load(['members:id,name', 'genres:id,name'])
                ->loadCount('followers')
                ->append(['address', 'type_label', 'music_embed']),
                // The page is public: member names only, not the pivot's
                // user ids and join dates.
                fn (Artist $a) => $a->members->each->makeHidden('pivot')),
            'upcomingShows' => ShowCardResource::collection(
                Show::query()
                    ->featuring($artist)
                    ->listed()
                    ->upcoming()
                    ->with(['confirmedLineup.artist', 'venue'])
                    ->limit(10)
                    ->get()
            )->resolve($request),
            // What this artist is looking for (members, gigs, a room).
            'listings' => Listing::query()
                ->active()
                ->where('artist_id', $artist->id)
                ->latest()
                ->get()
                ->map(fn (Listing $listing) => [
                    'id' => $listing->id,
                    'title' => $listing->title,
                    'typeLabel' => $listing->type_label,
                    'icon' => $listing->icon,
                    'url' => route('listings.show', $listing),
                ]),
            'sceneLinks' => SceneLinks::for(
                $artist->hometown,
                $artist->stateOrProvince,
                $artist->genres->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ),
            // Where this artist already gets paid. Public: a guest who
            // opened a shared link is exactly who this is for. Click
            // counts are the artist's own number and stay on the edit page.
            //
            // Queried separately rather than eager-loaded onto $artist: a
            // loaded relation serialises with the model, which would put
            // every column -- clicks included -- into the public payload
            // regardless of what the resource chooses to emit.
            'supportLinks' => SupportLinkResource::collection($artist->supportLinks()->get())->resolve($request),
            // Counts only, never names or places (ROADMAP principle 5).
            // "< 5" below the floor rather than an exact small number.
            'localFans' => $fanCounts->displayLocal($artist),
            // The wall: updates from the band and posts from fans, newest
            // first, in one stream. Ordered by time, never by likes.
            'wall' => $this->wallReader->wall($request, $artist),
            'wallMode' => $artist->wall_mode,
            // The members' own list of who they barred, so a ban is
            // visible and liftable where it was made.
            'wallBans' => $isMember
                ? $artist->wallBans()->with('user:id,name')->latest()->get()
                    ->map(fn ($ban) => [
                        'userId' => $ban->user_id,
                        'name' => $ban->user?->name,
                        'reason' => $ban->reason,
                        'at' => $ban->created_at?->toIso8601String(),
                    ])->all()
                : [],
            'wallModeLabel' => config('riff.wall.modes.'.$artist->wall_mode),
            // Whether *this* viewer may post, and in which voice. Guests
            // get false for both and are offered registration instead.
            // ArtistPolicy::postAsArtist, ::update and ::moderateWall are
            // all "is this user a member"; the write paths still go
            // through $this->authorize(), which stays the authority.
            'canPostAsArtist' => $isMember,
            'canPostOnWall' => $user?->can('postOnWall', $artist) ?? false,
            'canManage' => $isMember,
            'canFollow' => $user !== null,
            'isFollowing' => $isFollowing,
            // Whether this fan still wants to hear from them. Only
            // meaningful while following; false otherwise.
            'isMuted' => $isFollowing && ! $user->wantsNotificationsFrom($artist),
            'isBlocked' => $isBlocked,
            // ArtistPolicy::block refuses members their own artist.
            'canBlock' => $user !== null && ! $isMember,
        ]);
    }

    /**
     * Old numeric profile URLs (and anything still generating them from an
     * id) land on the slug URL permanently.
     */
    public function redirectToSlug(int $artistId): RedirectResponse
    {
        return redirect()->route('artists.show', Artist::findOrFail($artistId), 301);
    }

    public function edit(Request $request, Artist $artist): Response
    {
        $this->authorize('update', $artist);

        return Inertia::render('ArtistZone/Edit', [
            'artist' => $artist->load('genres:id,name')->append('music_embed'),
            // Members see their own click counts here -- the one place they
            // appear. See SupportLinkResource.
            'supportLinks' => $artist->supportLinks()->get()
                ->map(fn (ArtistSupportLink $link) => SupportLinkResource::make($link)->withClicks()->resolve($request))
                ->all(),
            'supportTypes' => collect((array) config('riff.support.types'))
                ->map(fn (array $config, string $value) => [
                    'value' => $value,
                    'label' => $config['label'],
                    'icon' => $config['icon'],
                    'hint' => $config['hint'] ?? null,
                    'hosts' => array_values((array) ($config['hosts'] ?? [])),
                ])
                ->values(),
            'wallModes' => collect((array) config('riff.wall.modes'))
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'maxSupportLinks' => (int) config('riff.support.max_links'),
            'maxSupportLabel' => (int) config('riff.support.max_label'),
            ...$this->formOptions(),
        ]);
    }

    public function update(ArtistUpdateRequest $request, Artist $artist): RedirectResponse
    {
        $this->authorize('update', $artist);

        $validated = $request->validated();

        $artist->fill(Arr::except($validated, 'genres'));
        $genreChanges = $artist->genres()->sync($validated['genres']);

        if ($artist->isDirty()) {
            $artist->save();
        } elseif (array_filter($genreChanges) !== []) {
            // A genre-only edit leaves the row itself clean, so no model
            // event would fire; touching keeps updated_at honest and lets
            // the map cache see the change.
            $artist->touch();
        }

        return to_route('artists.show', $artist);
    }

    /**
     * Archive (soft delete) the artist so a shared identity
     * can be restored if a member removes it by mistake.
     */
    public function destroy(Artist $artist): RedirectResponse
    {
        $this->authorize('delete', $artist);

        $artist->delete();

        return to_route('artists.index');
    }

    public function restore(Artist $artist): RedirectResponse
    {
        $this->authorize('restore', $artist);

        $artist->restore();

        return to_route('artists.show', $artist);
    }

    /**
     * Vocabulary shared by the create and edit forms, so the options a user
     * can pick always match what validation will accept.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'genreOptions' => Genre::query()->curated()->orderBy('name')->get(['id', 'name']),
            'artistTypes' => collect((array) config('riff.artist.types'))
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'maxGenres' => config('riff.artist.max_genres'),
        ];
    }
}
