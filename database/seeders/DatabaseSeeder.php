<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Database\Factories\Support\Towns;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(GenreSeeder::class);

        // A slice carries a real playable link so the development map has
        // profiles whose preview actually loads.
        $artists = Artist::factory()->count(60)->create()
            ->merge(Artist::factory()->count(40)->withMusic()->create());

        $users = User::factory()
            ->count(30)
            ->hasAttached($artists->random(rand(5, 10)))
            ->create();

        // Follows, so counts and the follow button have something to show.
        $users->each(function (User $user) use ($artists) {
            $user->followedArtists()->sync($artists->random(rand(0, 8))->pluck('id'));
        });

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

        $posts = Post::factory()
            ->count(40)
            ->recycle(User::all())
            ->create();

        // A queue with something in it: a few open complaints about artists
        // and posts, and some already settled, so the moderation page can
        // be seen in both of its states.
        Report::factory()->count(6)
            ->recycle($users)
            ->sequence(fn ($sequence) => $sequence->index % 2 === 0
                ? ['reportable_type' => Artist::class, 'reportable_id' => $artists->random()->id]
                : ['reportable_type' => Post::class, 'reportable_id' => $posts->random()->id])
            ->create(['reporter_id' => $users->random()->id]);

        Report::factory()->count(3)->resolved()->create([
            'reportable_type' => Artist::class,
            'reportable_id' => $artists->random()->id,
            'reporter_id' => $users->random()->id,
            'resolved_by_id' => User::firstWhere('email', 'test@example.com')->id,
        ]);
    }
}
