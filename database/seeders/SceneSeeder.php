<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\Genre;
use App\Models\LineupEntry;
use App\Models\Listing;
use App\Models\Report;
use App\Models\Show;
use App\Models\User;
use App\Models\Venue;
use App\Support\OnboardingIntent;
use App\Support\ReportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Support\Towns;
use Database\Seeders\Support\PilotScene;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * Venues and shows for the pilot scene, from the researched dataset in
 * database/seeders/data/pilot-scene.json: real Connecticut rooms at their
 * real addresses, a fictional private DIY space and a fictional dormant
 * venue, and a month of composed shows — tonight, this weekend, a backyard,
 * a park, a pending lineup slot, a cancellation. Dates are relative to the
 * moment it runs, so the map is always "this month".
 *
 * Built on PilotSeeder's acts, so it runs after it. Idempotent: it does
 * nothing if its venues exist. Demo and local development only — the shows
 * are plausible compositions, not real events, and the invented states
 * (pending, cancelled, listings) sit on fictional acts only; see _meta.
 */
class SceneSeeder extends Seeder
{
    private const TZ = 'America/New_York';

    public function run(): void
    {
        if (Venue::withTrashed()->where('name', 'Arch Street Tavern')->exists()) {
            $this->command?->info('The scene is already here; nothing was added.');

            return;
        }

        $submitter = User::where('email', 'test@example.com')->first() ?? User::factory()->create();
        $venues = collect(PilotScene::venues())
            ->mapWithKeys(fn (array $venue) => [$venue['name'] => $this->venue($venue, $submitter)]);

        $artist = fn (string $name) => Artist::where('name', $name)->first();
        $now = CarbonImmutable::now(self::TZ);
        $friday = $now->isFriday() ? $now : $now->next(CarbonImmutable::FRIDAY);

        foreach (PilotScene::shows() as $spec) {
            $hostArtist = $artist($spec['host']);

            if ($hostArtist === null) {
                continue;
            }

            $day = match ($spec['when']) {
                'today' => $now,
                'friday' => $friday,
                'saturday' => $friday->addDay(),
                'sunday' => $friday->addDays(2),
                'next-friday' => $friday->addDays(7),
                default => $now->addDays((int) $spec['days']),
            };

            $place = isset($spec['venue'])
                ? $venues[$spec['venue']]
                : [$spec['location']['label'], $spec['location']['town'], $spec['location']['note']];

            $show = $this->show($day->setTimeFromTimeString($spec['time']), $hostArtist, $place, $spec);
            $this->lineup(
                $show,
                $hostArtist,
                array_filter(array_map($artist, $spec['confirmed'] ?? [])),
                array_filter(array_map($artist, $spec['pending'] ?? [])),
                $spec['names'] ?? [],
            );
        }

        $this->rsvps();
        $this->threads();
        $this->listings($artist);
        $this->reports($venues->get('The Old Foundry'));

        // Scene pages for every town that now has artists, venues or shows.
        Artisan::call('riff:sync-places');
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function venue(array $spec, User $submitter): Venue
    {
        $town = Towns::named($spec['town']);
        $public = $spec['street'] !== null;

        $venue = new Venue([
            'name' => $spec['name'],
            'kind' => $spec['kind'],
            'address_visibility' => $public ? Venue::VISIBILITY_PUBLIC : Venue::VISIBILITY_PRIVATE,
            'street_address' => $spec['street'],
            'city' => $town['city'],
            'state' => $town['state'],
            'country' => 'USA',
            'age_policy' => $spec['age_policy'],
            'description' => $spec['description'],
        ]);

        // As the geocoder would place them: a public venue a few streets
        // off its town's centre (so it doesn't sit on the town's artists),
        // a private one at the centre exactly. Set before saving, so no
        // geocoding job is queued.
        $nudge = $public ? (crc32($spec['name']) % 40 - 20) / 4000 : 0;

        $venue->forceFill([
            'lat' => $town['lat'] + $nudge,
            'lng' => $town['lng'] - $nudge,
            'submitted_by' => $submitter->id,
        ])->save();

        if ($spec['dormant'] ?? false) {
            $venue->forceFill(['created_at' => now()->subYear()])->saveQuietly();
        }

        return $venue;
    }

    /**
     * @param  Venue|array{0: string, 1: string, 2: string}  $place  a venue, or [label, town, note] for a one-off
     * @param  array<string, mixed>  $extras
     */
    private function show(CarbonImmutable $when, Artist $host, Venue|array $place, array $extras): Show
    {
        $show = new Show([
            'title' => $extras['title'] ?? null,
            'starts_at' => $when->utc(),
            'doors_at' => isset($extras['doors']) ? $when->setTimeFromTimeString($extras['doors'])->utc() : null,
            'timezone' => self::TZ,
            'price' => $extras['price'] ?? null,
            'ticket_url' => $extras['ticket_url'] ?? null,
        ]);

        if ($place instanceof Venue) {
            $show->venue_id = $place->id;
        } else {
            [$label, $townName, $note] = $place;
            $town = Towns::named($townName);
            $show->fill([
                'location_name' => $label,
                'city' => $town['city'],
                'state' => $town['state'],
                'country' => 'USA',
                'location_note' => $note,
            ]);
            // Town-level, as the geocoder would set it.
            $show->forceFill(['lat' => $town['lat'], 'lng' => $town['lng']]);
        }

        $show->forceFill([
            'host_artist_id' => $host->id,
            'created_by' => $host->members()->value('users.id'),
            'source' => Show::SOURCE_MEMBER,
            'cancelled_at' => ($extras['cancelled'] ?? false) ? now() : null,
        ])->save();

        return $show;
    }

    /**
     * Statuses written directly: this is demo data standing in for answers
     * the other bands' members would have given through the Artist Zone.
     *
     * @param  array<int, Artist>  $confirmed
     * @param  array<int, Artist>  $pending
     * @param  list<string>  $names
     */
    private function lineup(Show $show, Artist $host, array $confirmed, array $pending, array $names): void
    {
        $position = 0;

        foreach ([[$host, LineupEntry::CONFIRMED], ...array_map(fn ($a) => [$a, LineupEntry::CONFIRMED], $confirmed), ...array_map(fn ($a) => [$a, LineupEntry::PENDING], $pending)] as [$artist, $status]) {
            $show->lineup()->make(['artist_id' => $artist->id, 'position' => $position++])
                ->forceFill(['status' => $status, 'responded_at' => $status === LineupEntry::CONFIRMED ? now() : null])
                ->save();
        }

        foreach ($names as $name) {
            $show->lineup()->create(['name' => $name, 'position' => $position++]);
        }
    }

    /**
     * "Looking for" posts from the dataset — fictional acts and posters
     * only, so no real band is said to be looking for anything.
     *
     * @param  \Closure(string): ?Artist  $artist
     */
    private function listings(\Closure $artist): void
    {
        $genres = Genre::pluck('id', 'name');
        $listener = User::query()->where('email', '!=', 'test@example.com')->whereDoesntHave('artists')->first()
            ?? User::factory()->create(['name' => 'Sam Okafor']);

        foreach (PilotScene::listings() as $spec) {
            $byArtist = $spec['artist'] !== null ? $artist($spec['artist']) : null;
            $poster = $spec['artist'] !== null ? $byArtist?->members()->first() : $listener;

            if ($poster === null) {
                continue;
            }

            $town = Towns::named($spec['town']);
            $listing = $poster->listings()->make([
                'type' => $spec['type'],
                'title' => $spec['title'],
                'body' => $spec['body'],
                'instruments' => $spec['instruments'],
                'city' => $town['city'],
                'state' => $town['state'],
                'country' => 'USA',
            ]);
            // Town-level, as the geocoder would place it.
            $listing->forceFill([
                'artist_id' => $byArtist?->id,
                'lat' => $town['lat'],
                'lng' => $town['lng'],
                'expires_at' => now()->addDays((int) $spec['days']),
            ])->save();
            $listing->genres()->sync($genres->only($spec['genres'])->values()->all());
        }
    }

    /**
     * The moderation queue sees the new surfaces too: one report about a
     * venue and one about a listing — fictional subjects only, like every
     * seeded report (see the dataset's _meta).
     */
    private function reports(?Venue $venue): void
    {
        $reporter = User::query()->where('email', '!=', 'test@example.com')->whereDoesntHave('artists')->first();

        if ($reporter === null) {
            return;
        }

        if ($venue !== null) {
            Report::factory()->about($venue)->create([
                'reporter_id' => $reporter->id,
                'reason' => 'other',
                'details' => 'This place shut down over a year ago; the page should probably say so.',
                'status' => ReportStatus::Open,
            ]);
        }

        $listing = Listing::query()->whereNull('artist_id')->first();

        if ($listing !== null) {
            Report::factory()->about($listing)->create([
                'reporter_id' => $reporter->id,
                'reason' => 'spam',
                'details' => 'Same post appears word-for-word in three other towns.',
                'status' => ReportStatus::Open,
            ]);
        }
    }

    /** A few going / interested, one of them listed by name. */
    /**
     * Threads on the upcoming shows: the questions a night actually gets,
     * and the host answering some of them in its own voice.
     *
     * Only on shows whose host is fictional -- a thread is invented words
     * about an act, and the dataset's consent rules keep those off real
     * ones (database/seeders/data/pilot-scene.json).
     */
    private function threads(): void
    {
        if (Comment::query()->whereMorphedTo('commentable', Show::class)->exists()) {
            return;
        }

        $fictional = PilotScene::fictionalActNames();
        $listeners = User::query()
            ->where('onboarding_intent', OnboardingIntent::Fan)
            ->inRandomOrder()
            ->limit(6)
            ->get();

        if ($listeners->isEmpty()) {
            return;
        }

        // A question the host answers, fans sorting out a lift between
        // themselves, and one question nobody has answered.
        $scripts = [
            [
                ['voice' => 'fan', 'content' => 'What time are you on?', 'replies' => [
                    ['voice' => 'artist', 'content' => 'Second of three, so about nine. Doors at seven.'],
                    ['voice' => 'fan', 'content' => 'Perfect, I can get there after work.'],
                ]],
                ['voice' => 'fan', 'content' => 'Is there parking anywhere near, or is it street only?', 'replies' => []],
            ],
            [
                ['voice' => 'fan', 'content' => 'Anyone driving from the shoreline? Happy to chip in for gas.', 'replies' => [
                    ['voice' => 'fan', 'content' => 'I am, leaving New Haven about six. Room for two.'],
                    ['voice' => 'fan', 'content' => 'Taking you up on that.'],
                ]],
                ['voice' => 'artist', 'content' => 'Set times are posted at the door. Come early, the opener is worth it.', 'replies' => []],
            ],
            [
                ['voice' => 'fan', 'content' => 'All ages or 21+? Bringing my younger brother.', 'replies' => [
                    ['voice' => 'artist', 'content' => 'All ages. Bring him.'],
                ]],
            ],
            // Reads as the run-up to a night that then got called off.
            [
                ['voice' => 'fan', 'content' => 'Been waiting for this one all month. See everyone Tuesday.', 'replies' => [
                    ['voice' => 'fan', 'content' => 'Same. First time seeing them.'],
                ]],
                ['voice' => 'artist', 'content' => 'Sorry everyone — we have had to call this off. Refunds at the point of sale, and we will find another date.', 'replies' => []],
            ],
        ];

        $shows = Show::query()
            ->listed()
            ->upcoming()
            ->whereHas('hostArtist', fn ($query) => $query->whereIn('name', $fictional))
            ->get();

        // Plus the cancelled show, so the demo carries a *closed* thread:
        // the page stays up, what was said stays readable, and nothing new
        // can be added. listed() excludes cancelled shows, so it is fetched
        // on its own.
        $cancelled = Show::query()
            ->whereNotNull('cancelled_at')
            ->whereHas('hostArtist', fn ($query) => $query->whereIn('name', $fictional))
            ->first();

        if ($cancelled !== null) {
            $shows = $shows->take(count($scripts) - 1)->push($cancelled);
        }

        foreach ($shows->take(count($scripts))->values() as $index => $show) {
            $host = $show->hostArtist;
            $member = $host?->members()->first();

            if ($host === null || $member === null) {
                continue;
            }

            foreach ($scripts[$index] as $position => $script) {
                $parent = $this->threadComment($show, $host, $member, $listeners, $script, $position, null);

                foreach ($script['replies'] as $offset => $reply) {
                    $this->threadComment($show, $host, $member, $listeners, $reply, $position + $offset + 1, $parent);
                }
            }
        }
    }

    /**
     * @param  Collection<int, User>  $listeners
     * @param  array<string, mixed>  $script
     */
    private function threadComment(
        Show $show,
        Artist $host,
        User $member,
        Collection $listeners,
        array $script,
        int $offset,
        ?Comment $parent,
    ): Comment {
        $factory = $script['voice'] === 'artist'
            ? Comment::factory()->asArtist($host, $member)
            : Comment::factory()->state(['user_id' => $listeners[$offset % $listeners->count()]->getKey()]);

        if ($parent !== null) {
            $factory = $factory->replyTo($parent);
        }

        // Dated before the show and after it was listed, so the thread
        // reads as the run-up to the night.
        $at = now()->subDays(2)->addHours($offset);

        return $factory->create([
            'commentable_type' => $show->getMorphClass(),
            'commentable_id' => $show->getKey(),
            'content' => $script['content'],
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function rsvps(): void
    {
        $listeners = User::query()->where('email', '!=', 'test@example.com')->inRandomOrder()->limit(8)->pluck('id');
        $upcoming = Show::query()->listed()->upcoming()->get();

        foreach ($upcoming as $index => $show) {
            foreach ($listeners->shuffle()->take(($index % 4) + 1) as $i => $userId) {
                $show->attendees()->syncWithoutDetaching([
                    $userId => ['status' => $i % 3 === 2 ? 'interested' : 'going', 'is_public' => $i === 0],
                ]);
            }
        }
    }
}
