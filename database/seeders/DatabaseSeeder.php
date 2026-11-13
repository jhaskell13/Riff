<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Show;
use App\Models\User;
use App\Notifications\ArtistPosted;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Notifications\NewFollower;
use App\Notifications\ShowAnnounced;
use App\Services\Notifier;
use Database\Factories\Support\Towns;
use Database\Seeders\Support\WallScripts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * The development dataset. Order matters: the moderator exists before
 * PilotSeeder settles a report against them, and the pilot scene fills
 * Hartford -- where the map opens -- before the regional scatter.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(GenreSeeder::class);

        $hartford = Towns::named('Hartford');

        // The development login, and the one account that can work the
        // report queue locally. In production the flag is not fillable and
        // riff:moderator is the only way to set it.
        User::factory()->moderator()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'city' => $hartford['city'],
            'state' => $hartford['state'],
            'lat' => $hartford['lat'],
            'lng' => $hartford['lng'],
        ]);

        // Greater Hartford: the pilot area, dense enough to demonstrate.
        $this->call(PilotSeeder::class);

        // Venues and a month of shows on the pilot's acts, for the scene map.
        $this->call(SceneSeeder::class);

        // The wider region, so panning away from the pilot still shows a
        // populated map. A slice carries a real playable link.
        $scattered = Artist::factory()->count(45)->create()
            ->merge(Artist::factory()->count(30)->withMusic()->create());

        $users = User::factory()
            ->count(20)
            ->hasAttached($scattered->random(rand(2, 5)))
            ->create();

        $users->each(function (User $user) use ($scattered) {
            $user->followedArtists()->sync($scattered->random(rand(0, 6))->pluck('id'));
        });

        // The old global feed survives as scaffolding (see CLAUDE.md), so
        // it still needs something in it. These carry no wall.
        Post::factory()
            ->count(25)
            ->recycle($users)
            ->create();

        // Walls on a slice of the scattered acts too, so panning the map
        // and opening any pin is likely to find a page with something on
        // it -- these are wholly invented, so no consent rule applies.
        $this->scatteredWalls($scattered->random(14), $users);

        // A bell with something in it for the development login: the
        // seeders write follows and posts directly, so none of the
        // triggers that normally produce notifications ever fire.
        $this->demoNotifications();

        // Scene pages for the scattered artists' towns too.
        Artisan::call('riff:sync-places');
    }

    /**
     * A handful of notifications for the development login, so the bell
     * and its folding are visible without anyone having to go and click
     * follow on four accounts.
     *
     * Goes through the Notifier like everything else, so the demo obeys
     * the same preference, block and folding rules as the real thing --
     * but sent immediately, because the local queue is the database driver
     * and a seeder has no worker behind it. Without that the demo bell
     * stays empty and five jobs sit in the table.
     */
    private function demoNotifications(): void
    {
        $demo = User::where('email', 'test@example.com')->first();

        if ($demo === null || $demo->notifications()->exists()) {
            return;
        }

        $notifier = app(Notifier::class);

        // An act with a wall, so the links in the bell go somewhere real.
        $artist = Artist::whereHas('wallPosts')->first();
        $post = $artist?->wallPosts()->latest('published_at')->first();

        if ($artist !== null && $post !== null) {
            $demo->followedArtists()->syncWithoutDetaching([$artist->id]);

            $notifier->send($demo, (new ArtistPosted($post, $artist))->causedBy(null), immediately: true);

            // Three more fans, folded into one line -- which is the part
            // worth seeing.
            foreach (['Noelia Nicolas', 'Jude Lind', 'Ashley Braun'] as $name) {
                $notifier->send($demo, (new NewFollower($artist, $name))->causedBy(null), immediately: true);
            }
        }

        $show = Show::query()->listed()->upcoming()->with('venue', 'hostArtist')->first();

        if ($show !== null && $show->hostArtist !== null) {
            $demo->followedArtists()->syncWithoutDetaching([$show->host_artist_id]);
            $notifier->send($demo, (new ShowAnnounced($show, $show->hostArtist))->causedBy(null), immediately: true);
        }
    }

    /**
     * A short wall for an invented act: one update from the band and a
     * couple of replies, so most pages have something to read.
     *
     * @param  Collection<int, Artist>  $artists
     * @param  Collection<int, User>  $users
     */
    private function scatteredWalls($artists, $users): void
    {
        $scripts = WallScripts::scripts();

        foreach ($artists->values() as $index => $artist) {
            $member = $artist->members()->first() ?? $users->random();
            $artist->members()->syncWithoutDetaching([$member->getKey()]);

            $fans = $users->random(min(4, $users->count()))->values();
            $fans->each(fn (User $fan) => $fan->followedArtists()->syncWithoutDetaching([$artist->id]));

            // One post from the script set, so the wording varies between
            // acts rather than every page reading identically.
            $script = $scripts[$index % count($scripts)][0];
            $postedAt = now()->subDays(rand(1, 20))->subHours(rand(0, 23));

            $post = $script['voice'] === 'artist'
                ? Post::factory()->asArtist($artist, $member)->create([
                    'content' => $script['content'],
                    'published_at' => $postedAt,
                ])
                : Post::factory()->onWall($artist)->create([
                    'user_id' => $fans->first()->getKey(),
                    'content' => $script['content'],
                    'published_at' => $postedAt,
                ]);

            foreach ($script['comments'] as $i => $comment) {
                $parent = $this->scatteredComment($artist, $member, $fans[$i % $fans->count()], $post, $comment, $postedAt, $i, null);

                foreach ($comment['replies'] ?? [] as $j => $reply) {
                    $this->scatteredComment($artist, $member, $fans[($i + $j + 1) % $fans->count()], $post, $reply, $postedAt, $i + $j + 1, $parent);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $script */
    private function scatteredComment(
        Artist $artist,
        User $member,
        User $fan,
        Post $post,
        array $script,
        Carbon $postedAt,
        int $offset,
        ?Comment $parent,
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
}
