<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Post;
use App\Models\User;
use Database\Factories\Support\Towns;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The development dataset, in the order it has to be built.
 *
 * The moderator account comes first because PilotSeeder settles one report
 * against whoever can work the queue, and the pilot scene comes before the
 * wider region so that Hartford -- where the map opens -- is the part that
 * looks inhabited. The rest is scatter: enough artists across four states
 * to exercise clustering, panning, and the search when the viewport leaves
 * the pilot area.
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

        // The wider region, so the map is not empty the moment it is panned
        // and the cluster/marker switch has something to switch over. A
        // slice carries a real playable link.
        $scattered = Artist::factory()->count(45)->create()
            ->merge(Artist::factory()->count(30)->withMusic()->create());

        $users = User::factory()
            ->count(20)
            ->hasAttached($scattered->random(rand(2, 5)))
            ->create();

        // Follows, so counts and the follow button have something to show
        // outside the pilot area too.
        $users->each(function (User $user) use ($scattered) {
            $user->followedArtists()->sync($scattered->random(rand(0, 6))->pluck('id'));
        });

        Post::factory()
            ->count(25)
            ->recycle($users)
            ->create();
    }
}
