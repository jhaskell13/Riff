<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\LineupEntry;
use App\Models\Show;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Factories\Support\Towns;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Venues and shows around Greater Hartford, so the scene map has something
 * to show: a club, a bar, a hall, a record store, a private DIY space, a
 * dormant venue that has faded off the map, and a month of shows — tonight,
 * this weekend, a backyard, a park, a pending lineup slot, a cancellation.
 *
 * Built on PilotSeeder's acts, so it runs after it. Every venue is invented,
 * like every act. Dates are relative to the moment it runs, so the map is
 * always "this month". Idempotent: it does nothing if its venues exist.
 */
class SceneSeeder extends Seeder
{
    private const TZ = 'America/New_York';

    /**
     * name, kind, town, street (null = private), age policy, description,
     * dormant (added long ago, nothing since — off the map, page stays).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string|null, 4: string, 5: string, 6?: bool}>
     */
    private const VENUES = [
        ['The Lantern Room', 'club', 'Hartford', '214 Lantern St', '18_plus',
            'Two hundred capacity, a proper sound system, and a green room the size of a closet. Most nights have three bands.'],
        ['Copper Tap', 'bar', 'West Hartford', '58 Kettle Rd', '21_plus',
            'Corner bar with a stage in the back. Country and folk on weekends, open mic on Tuesdays.'],
        ['Union Hall', 'hall', 'New Britain', '9 Foundry Way', 'all_ages',
            'Old union hall run by volunteers. All ages, no bar, cheap door, loud.'],
        ['Second Spin Records', 'record_store', 'Middletown', '71 Groove Ave', 'all_ages',
            'Used vinyl and the occasional in-store set between the bins.'],
        ['Basement Signal', 'diy', 'New Britain', null, 'all_ages',
            'A basement with a running calendar. Ask a punk for the address; be good to the neighbours.'],
        ['The Mill Yard', 'hall', 'Manchester', '3 Silk Mill Ln', 'varies',
            'Converted mill floor that hosts a show most weekends.'],
        ['The Old Foundry', 'club', 'Bristol', '400 Ingot St', '21_plus',
            'Hasn\'t listed a show in over a year — off the map until it does, though its page is still here.', true],
    ];

    public function run(): void
    {
        if (Venue::withTrashed()->where('name', 'The Lantern Room')->exists()) {
            $this->command?->info('The scene is already here; nothing was added.');

            return;
        }

        $submitter = User::where('email', 'test@example.com')->first() ?? User::factory()->create();
        $venues = collect(self::VENUES)->mapWithKeys(fn (array $venue) => [$venue[0] => $this->venue($venue, $submitter)]);

        $artist = fn (string $name) => Artist::where('name', $name)->first();
        $now = CarbonImmutable::now(self::TZ);
        $friday = $now->isFriday() ? $now : $now->next(CarbonImmutable::FRIDAY);
        $saturday = $friday->addDay();
        $sunday = $friday->addDays(2);

        $shows = [
            // [when, host, others (confirmed), pending, free-text, venue or one-off, extras]
            [$now->setTime(21, 0), 'The Paper Harbor', ['Quiet Transit'], [], ['Velvet Static'], $venues['The Lantern Room'], ['price' => '$12', 'doors' => '20:00']],
            [$friday->setTime(20, 0), 'The Silver Mill', ['Hollow Season'], ['Static Union'], [], $venues['Basement Signal'], ['price' => '$5–10 sliding']],
            [$saturday->setTime(20, 30), 'Northern Avenue', ['The Crooked Pines'], [], [], $venues['Copper Tap'], ['price' => '$10']],
            [$sunday->setTime(15, 0), 'Wire & Orchard', [], [], ['The Porch Lights'], ['Backyard on the north end', 'Middletown', 'Ask at the farmers market, or DM us'], ['price' => 'Free — bring a chair']],
            [$now->addDays(5)->setTime(18, 0), 'Glass Parade', [], [], [], $venues['Second Spin Records'], ['title' => 'Glass Parade in-store', 'price' => 'Free']],
            [$now->addDays(9)->setTime(19, 0), 'Thread City Ska', ['The Silver Mill'], [], ['The Upstrokes'], $venues['Union Hall'], ['price' => '$10']],
            [$now->addDays(12)->setTime(20, 0), 'Marisol Vega', [], [], [], $venues['The Lantern Room'], ['price' => '$15 adv / $18 door', 'ticket_url' => 'https://example.com/tickets']],
            [$now->addDays(16)->setTime(19, 30), 'Tapehead Audio', ['Aperture'], [], [], $venues['The Mill Yard'], ['title' => 'Slow Signals: an ambient night', 'price' => 'PWYC']],
            [$now->addDays(20)->setTime(17, 0), 'Sunken Radio', ['Jo Prentice'], [], [], ['Riverfront park bandshell', 'Glastonbury', 'Rain moves it indoors — check back here'], ['price' => 'Free']],
            [$now->addDays(7)->setTime(22, 0), 'DJ Lowtide', [], [], [], $venues['Copper Tap'], ['cancelled' => true]],
        ];

        foreach ($shows as [$when, $host, $confirmed, $pending, $names, $place, $extras]) {
            $hostArtist = $artist($host);

            if ($hostArtist === null) {
                continue;
            }

            $show = $this->show($when, $hostArtist, $place, $extras);
            $this->lineup($show, $hostArtist, array_filter(array_map($artist, $confirmed)), array_filter(array_map($artist, $pending)), $names);
        }

        $this->rsvps();
        $this->listings($artist);

        // Scene pages for every town that now has artists, venues or shows.
        Artisan::call('riff:sync-places');
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string|null, 4: string, 5: string, 6?: bool}  $spec
     */
    private function venue(array $spec, User $submitter): Venue
    {
        [$name, $kind, $townName, $street, $agePolicy, $description] = $spec;
        $town = Towns::named($townName);
        $public = $street !== null;

        $venue = new Venue([
            'name' => $name,
            'kind' => $kind,
            'address_visibility' => $public ? Venue::VISIBILITY_PUBLIC : Venue::VISIBILITY_PRIVATE,
            'street_address' => $street,
            'city' => $town['city'],
            'state' => $town['state'],
            'country' => 'USA',
            'age_policy' => $agePolicy,
            'description' => $description,
        ]);

        // As the geocoder would place them: a public venue a few streets
        // off its town's centre (so it doesn't sit on the town's artists),
        // a private one at the centre exactly. Set before saving, so no
        // geocoding job is queued.
        $nudge = $public ? (crc32($name) % 40 - 20) / 4000 : 0;

        $venue->forceFill([
            'lat' => $town['lat'] + $nudge,
            'lng' => $town['lng'] - $nudge,
            'submitted_by' => $submitter->id,
        ])->save();

        if ($spec[6] ?? false) {
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
     * "Looking for" posts: a punk band after a drummer (posted as the
     * band), a bassist after a band (posted as a person), a ska band after
     * an opener, and a practice room — one ending in a few days, so the
     * renew prompt has something to show.
     *
     * @param  \Closure(string): ?Artist  $artist
     */
    private function listings(\Closure $artist): void
    {
        $genres = Genre::pluck('id', 'name');
        $listener = User::query()->where('email', '!=', 'test@example.com')->whereDoesntHave('artists')->first()
            ?? User::factory()->create(['name' => 'Sam Okafor']);

        $posts = [
            ['members_wanted', 'The Silver Mill', null, 'New Britain', 'Drummer wanted — fast, short, loud',
                'Our drummer moved to Philly. We play basements and VFW halls, practise Tuesdays in New Britain, and split everything evenly. Need someone who can hold 200bpm for 90 seconds at a time.',
                ['drums'], ['Punk', 'Hardcore'], 30],
            ['band_wanted', null, $listener, 'Hartford', 'Bassist looking for a band',
                'Fifteen years on bass, own amp and a car. Into post-punk, shoegaze, anything with a groove. Can do two nights a week around Hartford.',
                ['bass'], ['Indie', 'Shoegaze'], 30],
            ['bill_wanted', 'Thread City Ska', null, 'Willimantic', 'Openers wanted for our spring shows',
                'Booking a run of all-ages shows in the spring and want local openers — ska, punk, reggae, anything with energy. Message us with a link to your music.',
                [], ['Ska', 'Reggae'], 30],
            ['practice_space', 'Quiet Transit', null, 'Hartford', 'Sharing our rehearsal room',
                'Soundproofed room above a print shop downtown, full backline. Free Mondays and Thursdays — looking for a band to split the rent.',
                [], [], 4],
        ];

        foreach ($posts as [$type, $artistName, $person, $townName, $title, $body, $instruments, $genreNames, $days]) {
            $byArtist = $artistName !== null ? $artist($artistName) : null;
            $poster = $person ?? $byArtist?->members()->first();

            if ($poster === null) {
                continue;
            }

            $town = Towns::named($townName);
            $listing = $poster->listings()->make([
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'instruments' => $instruments,
                'city' => $town['city'],
                'state' => $town['state'],
                'country' => 'USA',
            ]);
            // Town-level, as the geocoder would place it.
            $listing->forceFill([
                'artist_id' => $byArtist?->id,
                'lat' => $town['lat'],
                'lng' => $town['lng'],
                'expires_at' => now()->addDays($days),
            ])->save();
            $listing->genres()->sync($genres->only($genreNames)->values()->all());
        }
    }

    /** A few going / interested, one of them listed by name. */
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
