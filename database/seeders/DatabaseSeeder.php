<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Post;
use App\Models\User;
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

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        Post::factory()
            ->count(40)
            ->recycle(User::all())
            ->create();
    }
}
