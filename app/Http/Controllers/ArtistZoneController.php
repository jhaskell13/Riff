<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtistZone\ArtistStoreRequest;
use App\Http\Requests\ArtistZone\ArtistUpdateRequest;
use App\Http\Resources\ShowCardResource;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\LineupEntry;
use App\Models\Listing;
use App\Models\Show;
use App\Models\Venue;
use App\Services\ArtistPhotos;
use App\Support\SceneLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ArtistZoneController extends Controller
{
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
    public function show(Request $request, Artist $artist): Response|RedirectResponse
    {
        $this->authorize('view', $artist);

        // A historical slug resolves (Artist::resolveRouteBinding) but the
        // canonical URL is the current one.
        $requested = $request->route()->originalParameters()['artist'] ?? null;

        if ($requested !== null && $requested !== $artist->slug) {
            return redirect()->route('artists.show', $artist, 301);
        }

        $user = Auth::user();

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
            'canManage' => $user?->can('update', $artist) ?? false,
            'canFollow' => $user !== null,
            'isFollowing' => $user?->follows($artist) ?? false,
            'isBlocked' => $user?->blocks($artist) ?? false,
            'canBlock' => $user?->can('block', $artist) ?? false,
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

    public function edit(Artist $artist): Response
    {
        $this->authorize('update', $artist);

        return Inertia::render('ArtistZone/Edit', [
            'artist' => $artist->load('genres:id,name')->append('music_embed'),
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
