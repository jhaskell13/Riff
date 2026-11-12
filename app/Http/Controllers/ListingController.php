<?php

namespace App\Http\Controllers;

use App\Http\Requests\Listings\ListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Looking for" listings: post, edit, renew, close. Public to read; replies
 * go through ListingReplyController.
 */
class ListingController extends Controller
{
    public function create(Request $request): Response
    {
        $this->authorize('create', Listing::class);

        return Inertia::render('Listings/Create', [
            ...$this->formOptions($request->user()),
            'preset' => [
                'type' => $request->query('type'),
                'artist_id' => $request->integer('artist') ?: null,
            ],
        ]);
    }

    public function store(ListingRequest $request): RedirectResponse
    {
        $this->authorize('create', Listing::class);

        $artist = $this->artistFor($request);

        $listing = DB::transaction(function () use ($request, $artist) {
            $listing = $request->user()->listings()->make($request->listingAttributes());
            $listing->forceFill([
                'artist_id' => $artist?->id,
                'expires_at' => now()->addDays((int) config('riff.listings.days')),
            ])->save();
            $listing->genres()->sync($request->validated('genres') ?? []);

            return $listing;
        });

        return to_route('listings.show', $listing);
    }

    public function show(Request $request, Listing $listing): Response
    {
        $this->authorize('view', $listing);

        $user = $request->user();
        $listing->load(['artist', 'genres:id,name', 'user:id,name']);
        $manages = $user !== null && $listing->isManagedBy($user);

        return Inertia::render('Listings/Show', [
            'listing' => ListingResource::make($listing)->resolve($request),
            'canManage' => $manages && $listing->closed_at === null,
            'canRenew' => $user?->can('renew', $listing) ?? false,
            'canReply' => $user?->can('reply', $listing) ?? false,
            'hasReplied' => $user !== null && $listing->replies()->where('user_id', $user->id)->exists(),
            // The poster's side only: how many replies were relayed.
            'replyCount' => $manages ? $listing->replies()->count() : null,
            'isGuest' => $user === null,
        ]);
    }

    public function edit(Request $request, Listing $listing): Response
    {
        $this->authorize('update', $listing);

        return Inertia::render('Listings/Edit', [
            ...$this->formOptions($request->user()),
            'listing' => [
                'id' => $listing->id,
                'type' => $listing->type,
                'artist_id' => $listing->artist_id,
                'title' => $listing->title,
                'body' => $listing->body,
                'instruments' => $listing->instruments ?? [],
                'genres' => $listing->genres()->pluck('genres.id'),
                'city' => $listing->city,
                'state' => $listing->state,
                'country' => $listing->country,
            ],
        ]);
    }

    public function update(ListingRequest $request, Listing $listing): RedirectResponse
    {
        $this->authorize('update', $listing);

        $artist = $this->artistFor($request);

        DB::transaction(function () use ($request, $listing, $artist) {
            $listing->fill($request->listingAttributes());
            $listing->forceFill(['artist_id' => $artist?->id])->save();
            $listing->genres()->sync($request->validated('genres') ?? []);
        });

        return to_route('listings.show', $listing);
    }

    /** Another full run, from today — only near or past the end. */
    public function renew(Listing $listing): RedirectResponse
    {
        $this->authorize('renew', $listing);

        $listing->forceFill(['expires_at' => now()->addDays((int) config('riff.listings.days'))])->save();

        return back();
    }

    /** Found someone: off every surface, page kept for the record. */
    public function close(Listing $listing): RedirectResponse
    {
        $this->authorize('close', $listing);

        $listing->forceFill(['closed_at' => now()])->save();

        return back();
    }

    /**
     * The artist a listing is posted as, if any: only one the poster is a
     * member of.
     */
    private function artistFor(ListingRequest $request): ?Artist
    {
        $id = $request->validated('artist_id');

        if ($id === null) {
            return null;
        }

        $artist = Artist::findOrFail($id);
        $this->authorize('update', $artist);

        return $artist;
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(User $user): array
    {
        return [
            'types' => collect((array) config('riff.listings.types'))
                ->map(fn (array $type, string $value) => [
                    'value' => $value,
                    'label' => $type['label'],
                    'asArtist' => $type['as_artist'],
                    'icon' => $type['icon'],
                ])
                ->values(),
            'instruments' => collect((array) config('riff.listings.instruments'))
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'genreOptions' => Genre::query()->curated()->orderBy('name')->get(['id', 'name']),
            // With each artist's town, so choosing one fills the location.
            'artistOptions' => $user->artists()->orderBy('name')
                ->get(['artists.id', 'artists.name', 'artists.hometown', 'artists.stateOrProvince', 'artists.country'])
                ->map(fn (Artist $artist) => [
                    'value' => $artist->id,
                    'label' => $artist->name,
                    'city' => $artist->hometown,
                    'state' => $artist->stateOrProvince,
                    'country' => $artist->country,
                ]),
            'home' => ['city' => $user->city, 'state' => $user->state, 'country' => $user->country],
        ];
    }
}
