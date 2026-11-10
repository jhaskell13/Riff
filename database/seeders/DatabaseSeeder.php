<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Post;
use App\Models\User;
use Database\Factories\Support\Towns;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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

        Post::factory()
            ->count(25)
            ->recycle($users)
            ->create();
    }
}
