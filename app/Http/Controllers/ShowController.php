<?php

namespace App\Http\Controllers;

use App\Http\Requests\Shows\ShowRequest;
use App\Http\Resources\ShowResource;
use App\Models\Artist;
use App\Models\LineupEntry;
use App\Models\Show;
use App\Models\User;
use App\Notifications\ShowAnnounced;
use App\Services\Lineup;
use App\Services\Notifier;
use App\Services\WallReader;
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
    public function __construct(
        private WallReader $threads,
        private Notifier $notifier,
    ) {}

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

        // After the transaction, not inside it: a notification about a
        // show that then failed to save would be a lie, and the followers
        // query has no business holding the write open.
        $this->announceToFollowers($show);

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
            // The night's thread. Goes read-only after the show rather
            // than disappearing: what was said still explains the night to
            // anyone arriving late.
            'thread' => $this->threads->thread($request, $show),
            'canManage' => $user?->can('update', $show) ?? false,
            'canRsvp' => $user?->can('rsvp', $show) ?? false,
            'isGuest' => $user === null,
        ]);
    }

    /**
     * Tell the fans of every confirmed act that there is a new date.
     *
     * Confirmed only, like every public surface: a pending invitation is
     * not a date anyone should plan around, and announcing it would name
     * an act that has not agreed to be named. When a pending act later
     * confirms, LineupEntryController announces it then.
     */
    private function announceToFollowers(Show $show): void
    {
        if (! $show->listed()->whereKey($show->id)->exists()) {
            return;
        }

        $show->load('confirmedLineup.artist', 'venue');

        foreach ($show->confirmedLineup as $entry) {
            if ($entry->artist === null) {
                continue;
            }

            $this->notifier->send(
                $entry->artist->followers()->get(),
                (new ShowAnnounced($show, $entry->artist))->causedBy(request()->user()),
            );
        }
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
