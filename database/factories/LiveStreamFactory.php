<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\LiveStream;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LiveStream>
 */
class LiveStreamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artist_id' => Artist::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'status' => 'scheduled',
        ];
    }
}
