<?php

namespace App\Http\Controllers;

use App\Http\Requests\Shows\ShowRequest;
use App\Http\Resources\ShowResource;
use App\Models\Artist;
use App\Models\LineupEntry;
use App\Models\Show;
use App\Models\User;
use App\Services\Lineup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shows: listed by members of a host artist, public to everyone, with
 * other Riff artists on the bill confirming their own slot.
 */
class ShowController extends Controller
{
    public function create(Request $request): Response
    {
        $this->authorize('create', Show::class);

        return Inertia::render('Shows/Create', [
            ...$this->formOptions($request->user()),
            // "Add a show" from one artist's row in the zone preselects it.
            'hostArtistId' => $request->integer('artist') ?: null,
        ]);
    }

    public function store(ShowRequest $request, Lineup $lineup): RedirectResponse
    {
        $this->authorize('create', Show::class);

        $host = Artist::findOrFail($request->validated('host_artist_id'));
        // Hosting means listing on that artist's behalf: members only.
        $this->authorize('update', $host);

        $show = DB::transaction(function () use ($request, $lineup, $host) {
            $show = new Show($request->showAttributes());
            $show->forceFill([
                'host_artist_id' => $host->id,
                'created_by' => $request->user()->id,
                'source' => Show::SOURCE_MEMBER,
            ])->save();

            $lineup->sync($show, $request->lineup(), $request->user());

            return $show;
        });

        return to_route('shows.show', $show);
    }

    public function show(Request $request, Show $show): Response
    {
        $this->authorize('view', $show);

        $show->load(['confirmedLineup.artist', 'venue', 'hostArtist']);
        $user = $request->user();

        $rsvps = $show->attendees();
        $mine = $user === null ? null : $show->attendees()->whereKey($user->id)->first()?->pivot;

        return Inertia::render('Shows/Show', [
            'show' => ShowResource::make($show)->resolve($request),
            'rsvp' => [
                'going' => (clone $rsvps)->wherePivot('status', 'going')->count(),
                'interested' => (clone $rsvps)->wherePivot('status', 'interested')->count(),
                // Names only for fans who chose to be listed.
                'publicGoing' => $show->attendees()
                    ->wherePivot('status', 'going')
                    ->wherePivot('is_public', true)
                    ->orderBy('show_rsvps.created_at')
                    ->limit(20)
                    ->pluck('users.name'),
                'mine' => $mine === null ? null : [
                    'status' => $mine->getAttribute('status'),
                    'isPublic' => (bool) $mine->getAttribute('is_public'),
                ],
            ],
            'canManage' => $user?->can('update', $show) ?? false,
            'canRsvp' => $user?->can('rsvp', $show) ?? false,
            'isGuest' => $user === null,
        ]);
    }

    public function edit(Request $request, Show $show): Response
    {
        $this->authorize('update', $show);

        $show->load(['lineup.artist', 'venue', 'hostArtist']);
        $start = $show->localStart();

        return Inertia::render('Shows/Edit', [
            ...$this->formOptions($request->user()),
            'show' => [
                'id' => $show->id,
                'hostArtistId' => $show->host_artist_id,
                'hostName' => $show->hostArtist?->name,
                'title' => $show->title,
                'venue' => $show->venue === null ? null : [
                    'id' => $show->venue->id,
                    'name' => $show->venue->name,
                    'area' => $show->venue->area,
                ],
                'location_name' => $show->location_name,
                'city' => $show->city,
                'state' => $show->state,
                'country' => $show->country,
                'location_note' => $show->location_note,
                'date' => $start->format('Y-m-d'),
                'start_time' => $start->format('H:i'),
                'doors_time' => $show->localDoors()?->format('H:i'),
                'timezone' => $show->timezone,
                'price' => $show->price,
                'ticket_url' => $show->ticket_url,
                'age_policy' => $show->age_policy,
                'description' => $show->description,
                // The host sees every entry with its answer, so they know
                // who still has to confirm.
                'lineup' => $show->lineup->map(fn (LineupEntry $entry) => [
                    'artist_id' => $entry->artist_id,
                    'name' => $entry->display_name,
                    'status' => $entry->status,
                ])->values(),
            ],
        ]);
    }

    public function update(ShowRequest $request, Show $show, Lineup $lineup): RedirectResponse
    {
        $this->authorize('update', $show);

        DB::transaction(function () use ($request, $show, $lineup) {
            $show->fill($request->showAttributes());

            // Confirming "Friday at the Lantern Room" isn't consent to
            // "Saturday in a backyard": a new time or place re-asks.
            $material = $show->isDirty(['starts_at', 'venue_id', 'location_name', 'city', 'state']);

            $show->save();

            $lineup->sync($show, $request->lineup(), $request->user(), $material);
        });

        return to_route('shows.show', $show);
    }

    /**
     * Cancelling keeps the page, marked cancelled: people holding the link
     * need to find out. Moderator removal is the soft delete.
     */
    public function cancel(Show $show): RedirectResponse
    {
        $this->authorize('cancel', $show);

        $show->forceFill(['cancelled_at' => now()])->save();

        return to_route('shows.show', $show);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(User $user): array
    {
        return [
            'hostOptions' => $user->artists()
                ->orderBy('name')
                ->get(['artists.id', 'artists.name'])
                ->map(fn (Artist $artist) => ['value' => $artist->id, 'label' => $artist->name])
                ->values(),
            'agePolicies' => collect((array) config('riff.age_policies'))
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'maxLineup' => (int) config('riff.shows.max_lineup'),
        ];
    }
}
