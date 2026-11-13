<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\Genre;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Models\WallModerationEvent;
use App\Services\WallModeration;
use App\Support\AuthorContext;
use App\Support\ModerationAction;
use App\Support\OnboardingIntent;
use App\Support\ReportStatus;
use Database\Factories\Support\Towns;
use Database\Seeders\Support\PilotScene;
use Database\Seeders\Support\PlaceholderPhoto;
use Database\Seeders\Support\WallScripts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The pilot scene: Greater Hartford and the towns around it, seeded from
 * the researched dataset in database/seeders/data/pilot-scene.json — real
 * Connecticut acts (factual bios, verified links) plus a handful of
 * invented ones. Idempotent: an act that already exists by name is left
 * alone. Demo and local development only; see the JSON's _meta.
 *
 * Consent boundary: anything that *attributes* something to an act — feed
 * posts in its voice, entries in the report queue, hidden/archived states —
 * is seeded onto the fictional acts only. Real acts get a profile, a
 * factual bio, generated placeholder art (never their real photos), and
 * only music links verified during research.
 */
class PilotSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(GenreSeeder::class);

        $genreIds = Genre::pluck('id', 'name');
        $artists = collect();

        foreach (PilotScene::acts() as $act) {
            $existing = Artist::withTrashed()->firstWhere('name', $act['name']);

            if ($existing !== null) {
                $artists->push($existing);

                continue;
            }

            $town = Towns::named($act['town']);

            $artist = Artist::factory()
                // Placeholder tracks only on fictional acts; real acts get
                // their own verified link or none at all.
                ->when($act['placeholder_music'] ?? false, fn ($factory) => $factory->withMusic())
                ->create([
                    'name' => $act['name'],
                    'artist_type' => $act['type'],
                    'hometown' => $town['city'],
                    'stateOrProvince' => $town['state'],
                    'country' => 'USA',
                    'lat' => $town['lat'],
                    'lng' => $town['lng'],
                    'biography' => $act['bio'],
                    'show_on_map' => ! ($act['hidden_from_map'] ?? false),
                    ...(isset($act['music_url']) ? ['music_url' => $act['music_url']] : []),
                ]);

            $artist->genres()->sync($genreIds->only($act['genres'])->values()->all());

            PlaceholderPhoto::attach($artist);

            if ($act['support'] ?? false) {
                $this->supportLinks($artist, $act);
            }

            $this->attachMembers($artist, $act, $town);

            if ($act['archived'] ?? false) {
                $artist->delete();
            }

            $artists->push($artist);
        }

        $onMap = $artists->filter(fn (Artist $artist) => $artist->show_on_map && ! $artist->trashed());
        $fictional = $onMap->whereIn('name', PilotScene::fictionalActNames());

        $this->listeners($onMap);
        // Walls carry simulated conversation on real acts too (owner
        // decision, November 2026): the demo has to show a populated
        // scene, and an empty wall under a real band shows nothing. The
        // report queue stays fictional -- being reported is a judgement,
        // not a public fact.
        //
        // The first few fictional acts get full wall conversations; the
        // rest get a lone artist-voice post, so the demo shows both a busy
        // wall and a quiet one. They are split rather than overlapping, or
        // an act would carry the same line twice.
        $this->walls($onMap);
        // One hidden comment and one barred listener, so a member logging
        // in sees what artist-level moderation looks like -- including the
        // undo and the trail. Fictional acts only, like every other
        // attributed state.
        $this->seedWallModeration($fictional);
        // Anything with no scripted wall still gets a lone artist-voice
        // post, so a quiet page is represented too.
        $this->posts($fictional);
        $this->reports($fictional);
    }

    /**
     * Where an act asks to be supported. For a real act this is their own
     * public Bandcamp and nothing else; the click counts are invented, and
     * exist so the Artist Zone has something to show.
     *
     * @param  array<string, mixed>  $act
     */
    private function supportLinks(Artist $artist, array $act): void
    {
        foreach (array_values($act['support']) as $position => $link) {
            $artist->supportLinks()->create([
                'type' => $link['type'],
                'url' => $link['url'],
                'label' => $link['label'] ?? null,
                'position' => $position,
                // Not fillable, by design: a request may never set this.
            ])->forceFill(['clicks' => (int) ($link['clicks'] ?? 0)])->save();
        }
    }

    /** The member account behind an act, local to the same town. */
    /**
     * The people behind an act.
     *
     * Real acts get their publicly credited members by name -- that is
     * what makes the demo look like a scene rather than a directory. The
     * accounts themselves are invented: every one uses an RFC-reserved
     * example.* address (UserFactory::safeEmail), so **a demo digest or
     * notification can never reach a real person's inbox.** That is the
     * one hard line in the dataset's rules, and PilotDatasetTest enforces
     * it on the way out.
     *
     * @param  array<string, mixed>  $act
     * @param  array<string, mixed>  $town
     */
    private function attachMembers(Artist $artist, array $act, array $town): void
    {
        $names = $act['members'] ?? [];

        if ($names === []) {
            $artist->members()->attach($this->memberFor($artist, $town));

            return;
        }

        foreach ($names as $name) {
            $artist->members()->attach($this->memberFor($artist, $town, (string) $name));
        }
    }

    private function memberFor(Artist $artist, array $town, ?string $name = null): User
    {
        return User::factory()->create([
            ...$name === null ? [] : ['name' => $name],
            'city' => $town['city'],
            'state' => $town['state'],
            'lat' => $town['lat'],
            'lng' => $town['lng'],
            'onboarding_intent' => OnboardingIntent::Artist,
        ]);
    }

    /**
     * Local listeners whose follows make the cards feel inhabited.
     *
     * @param  Collection<int, Artist>  $artists
     */
    private function listeners(Collection $artists): void
    {
        if ($artists->isEmpty() || User::where('onboarding_intent', OnboardingIntent::Fan)->exists()) {
            return;
        }

        $towns = ['Hartford', 'West Hartford', 'New Britain', 'Middletown', 'Manchester', 'Bristol', 'Glastonbury'];

        foreach (range(1, 18) as $ignored) {
            $town = Towns::named(Arr::random($towns));

            User::factory()
                ->create([
                    'city' => $town['city'],
                    'state' => $town['state'],
                    'lat' => $town['lat'],
                    'lng' => $town['lng'],
                    'onboarding_intent' => OnboardingIntent::Fan,
                ])
                ->followedArtists()
                ->sync($artists->random(rand(2, min(7, $artists->count())))->pluck('id'));
        }
    }

    /**
     * Posts by the acts themselves: a member posting as the artist.
     *
     * @param  Collection<int, Artist>  $artists
     */
    private function posts(Collection $artists): void
    {
        $lines = [
            'New single is up. Thanks to everyone who came out to the release show.',
            'Practice space flooded. If anyone has a room in the area for a month, we are asking.',
            'Playing Friday, doors at 8. Three bands, five dollars, bring a friend.',
            'Demos are finally mixed. Streaming everywhere this week.',
            'Looking for a drummer who can make Thursdays work.',
            'Recorded live to tape yesterday and kept the mistakes.',
            'Our van passed inspection. Tour is back on.',
            'Open mic tonight if you want to try something new on a kind room.',
            'Added a second date because the first sold out. Still cannot quite believe that.',
            'Thanks to the crowd last night for singing the second verse when I forgot it.',
        ];

        foreach ($artists->random(min(10, $artists->count())) as $index => $artist) {
            $member = $artist->members()->first();

            // Idempotent per act, like the acts themselves: re-running the
            // seeder on an existing database must not give a band the same
            // announcement twice.
            if ($member === null || $artist->wallPosts()->exists()) {
                continue;
            }

            Post::factory()->asArtist($artist, $member)->create([
                'content' => $lines[$index % count($lines)],
                'published_at' => now()->subDays(rand(0, 21))->subHours(rand(0, 23)),
            ]);
        }
    }

    /**
     * Conversations on the walls: fans posting, fans answering each other,
     * and the band replying in its own voice.
     *
     * Fictional acts only, like the artist-voice posts above. A wall full
     * of invented fans saying invented things about a real band attributes
     * a community to them that does not exist.
     *
     * @param  Collection<int, Artist>  $artists
     */
    private function walls(Collection $artists): void
    {
        $fans = User::where('onboarding_intent', OnboardingIntent::Fan)->get();

        if ($fans->isEmpty() || $artists->isEmpty()) {
            return;
        }

        // The first act gets the busy wall -- enough comments and replies
        // that the "show more" controls appear in the demo without anyone
        // typing a word. The rest get ordinary walls, cycling through the
        // shapes in WallScripts.
        $scripts = WallScripts::scripts();
        $scripted = WallScripts::forActs();
        $acts = $artists->values();

        foreach ($acts as $index => $artist) {
            $member = $artist->members()->first();

            // Idempotent per act, like the acts themselves: re-running
            // must not give a band the same thread twice.
            if ($member === null || $artist->wallPosts()->exists()) {
                continue;
            }

            // Posting fans follow the artist, which is what the default
            // wall mode requires of a real one.
            $local = $fans->random(min(6, $fans->count()))->values();
            $local->each(fn (User $fan) => $fan->followedArtists()->syncWithoutDetaching([$artist->id]));

            // An act with a script of its own gets it -- written around
            // that band's actual records and rooms. Everyone else cycles
            // through the generic shapes.
            $wall = $scripted[$artist->name]
                ?? ($index === 0 ? WallScripts::busy() : $scripts[$index % count($scripts)]);

            // Oldest first, so the newest post ends up at the top of the
            // wall and the timestamps read in order.
            $age = count($wall) * 4;

            foreach ($wall as $post) {
                $this->conversation($artist, $member, $local, $post, $age);
                $age -= 4;
            }
        }
    }

    /**
     * A demo of artist-level moderation: something hidden (with its trail
     * and its undo) and somebody barred from a wall.
     *
     * Nothing destructive, because nothing artist-level ever is -- that is
     * the whole point of the feature.
     *
     * @param  Collection<int, Artist>  $artists
     */
    private function seedWallModeration(Collection $artists): void
    {
        if (WallModerationEvent::query()->exists()) {
            return;
        }

        $artist = $artists->first(fn (Artist $a) => $a->wallPosts()
            ->whereHas('comments', fn ($q) => $q->whereNull('parent_id'))
            ->exists());

        $member = $artist?->members()->first();

        if ($artist === null || $member === null) {
            return;
        }

        $moderation = app(WallModeration::class);

        // Hide the newest fan comment on the wall, so the demo shows a
        // hidden row with "Hidden by <member>" and an Unhide beside it.
        $comment = Comment::query()
            ->whereIn('commentable_id', $artist->wallPosts()->pluck('id'))
            ->where('commentable_type', (new Post)->getMorphClass())
            ->where('author_context', AuthorContext::Fan)
            ->whereNull('parent_id')
            ->latest('id')
            ->first();

        if ($comment !== null) {
            $moderation->hide($artist, $member, $comment, 'Off topic — asked them to take it to the listing.');
        }

        // And bar one listener who is not a fan of anything else, so the
        // members' ban list has a row to lift.
        $listener = User::where('onboarding_intent', OnboardingIntent::Fan)
            ->whereNotIn('id', $artist->members()->pluck('users.id'))
            ->latest('id')
            ->first();

        if ($listener !== null) {
            $moderation->ban($artist, $member, $listener, 'Repeatedly posting the same promo under every update.');
        }
    }

    /**
     * One post and its thread.
     *
     * @param  Collection<int, User>  $fans
     * @param  array<string, mixed>  $script
     */
    private function conversation(Artist $artist, User $member, Collection $fans, array $script, int $ageInDays): void
    {
        $fanAt = fn (int $i) => $fans[$i % $fans->count()];
        $postedAt = now()->subDays(max(0, $ageInDays))->subHours(rand(0, 20));

        $post = $script['voice'] === 'artist'
            ? Post::factory()->asArtist($artist, $member)->create([
                'content' => $script['content'],
                'published_at' => $postedAt,
            ])
            : Post::factory()->onWall($artist)->create([
                'user_id' => $fanAt(0)->getKey(),
                'content' => $script['content'],
                'published_at' => $postedAt,
            ]);

        // A couple of likes, so the counter is not always zero. Nothing is
        // ever ordered by them.
        foreach ($fans->take(rand(0, $fans->count())) as $fan) {
            $post->likers()->attach($fan->getKey());
            $post->increment('likes');
        }

        foreach ($script['comments'] as $commentIndex => $comment) {
            $parent = $this->comment($artist, $member, $fanAt($commentIndex + 1), $post, $comment, $postedAt, $commentIndex);

            foreach ($comment['replies'] ?? [] as $replyIndex => $reply) {
                $this->comment(
                    $artist,
                    $member,
                    $fanAt($commentIndex + $replyIndex + 2),
                    $post,
                    $reply,
                    $postedAt,
                    $commentIndex + $replyIndex + 1,
                    $parent,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $script
     */
    private function comment(
        Artist $artist,
        User $member,
        User $fan,
        Post $post,
        array $script,
        Carbon $postedAt,
        int $offset,
        ?Comment $parent = null,
    ): Comment {
        $factory = $script['voice'] === 'artist'
            ? Comment::factory()->asArtist($artist, $member)
            : Comment::factory()->state(['user_id' => $fan->getKey()]);

        if ($parent !== null) {
            $factory = $factory->replyTo($parent);
        }

        $at = $postedAt->copy()->addHours($offset + 1);

        return $factory->create([
            'commentable_type' => $post->getMorphClass(),
            'commentable_id' => $post->getKey(),
            'content' => $script['content'],
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * Open complaints and one settled, anchored on pilot acts.
     *
     * @param  Collection<int, Artist>  $artists
     */
    private function reports(Collection $artists): void
    {
        if ($artists->count() < 3 || Report::query()->exists()) {
            return;
        }

        $reporters = User::where('onboarding_intent', OnboardingIntent::Fan)->take(3)->get();

        if ($reporters->isEmpty()) {
            return;
        }

        $subjects = $artists->random(3)->values();

        $complaints = [
            ['impersonation', 'This is not them. The real band has a different line-up and they have asked people to stop.'],
            ['wrong_location', 'They are not in this town any more, they moved last year.'],
            ['spam', 'Same copy-and-paste message under every local act.'],
        ];

        foreach ($complaints as $index => [$reason, $details]) {
            Report::factory()->about($subjects[$index])->create([
                'reporter_id' => $reporters[$index % $reporters->count()]->id,
                'reason' => $reason,
                'details' => $details,
                'status' => ReportStatus::Open,
                'created_at' => now()->subDays(3 - $index),
            ]);
        }

        $post = Post::query()->whereNotNull('artist_id')->first();

        if ($post !== null) {
            Report::factory()->about($post)->create([
                'reporter_id' => $reporters->first()->id,
                'reason' => 'not_music',
                'details' => 'This is an advert for a phone repair shop.',
                'status' => ReportStatus::Open,
            ]);
        }

        $moderator = User::where('is_moderator', true)->first();

        if ($moderator !== null) {
            Report::factory()->about($artists->random())->create([
                'reporter_id' => $reporters->first()->id,
                'reason' => 'other',
                'details' => 'Reported by mistake, please ignore.',
                'status' => ReportStatus::Dismissed,
                'resolution' => ModerationAction::Dismiss,
                'resolved_by_id' => $moderator->id,
                'resolved_at' => now()->subDay(),
                'moderator_note' => 'Nothing wrong with the profile; reporter withdrew it.',
            ]);
        }
    }
}
