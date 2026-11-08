<?php

namespace Tests\Feature;

use App\Models\LiveStream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamValidationTest extends TestCase
{
    use RefreshDatabase;

    private function memberFor(LiveStream $stream): User
    {
        $user = User::factory()->create();
        $stream->artist->members()->attach($user);

        return $user;
    }

    public function test_stream_creation_requires_an_existing_artist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('stream.store'), ['artist_id' => 999, 'title' => 'Ghost'])
            ->assertSessionHasErrors('artist_id');
    }

    public function test_send_data_rejects_oversized_chunks(): void
    {
        $stream = LiveStream::factory()->create(['status' => 'live']);
        $member = $this->memberFor($stream);

        $this->actingAs($member)
            ->postJson(route('stream.data', $stream), [
                'chunk' => str_repeat('a', 1500001),
                'type' => 'video',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('chunk');
    }

    public function test_send_data_rejects_unknown_media_types(): void
    {
        $stream = LiveStream::factory()->create(['status' => 'live']);
        $member = $this->memberFor($stream);

        $this->actingAs($member)
            ->postJson(route('stream.data', $stream), [
                'chunk' => 'abc',
                'type' => 'script',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }
}
