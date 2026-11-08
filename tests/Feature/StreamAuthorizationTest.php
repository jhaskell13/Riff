<?php

namespace Tests\Feature;

use App\Events\LiveStreamStarted;
use App\Models\Artist;
use App\Models\LiveStream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StreamAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function memberOf(Artist $artist): User
    {
        $user = User::factory()->create();
        $artist->members()->attach($user);

        return $user;
    }

    public function test_a_member_can_create_a_stream_for_their_artist(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        $this->actingAs($member)
            ->post(route('stream.store'), [
                'artist_id' => $artist->id,
                'title' => 'Live from the garage',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('live_streams', [
            'artist_id' => $artist->id,
            'title' => 'Live from the garage',
            'status' => 'scheduled',
        ]);
    }

    public function test_a_non_member_cannot_create_a_stream_for_another_artist(): void
    {
        $artist = Artist::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post(route('stream.store'), [
                'artist_id' => $artist->id,
                'title' => 'Impersonation attempt',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('live_streams', 0);
    }

    public function test_a_member_can_start_and_end_their_stream(): void
    {
        Event::fake([LiveStreamStarted::class]);

        $stream = LiveStream::factory()->create();
        $member = $this->memberOf($stream->artist);

        $this->actingAs($member)->post(route('stream.start', $stream))->assertOk();
        $this->assertSame('live', $stream->fresh()->status);
        Event::assertDispatched(LiveStreamStarted::class, fn ($event) => $event->streamId === $stream->id);

        $this->actingAs($member)->post(route('stream.end', $stream))->assertOk();
        $this->assertSame('ended', $stream->fresh()->status);
    }

    public function test_a_non_member_cannot_start_a_stream(): void
    {
        Event::fake([LiveStreamStarted::class]);

        $stream = LiveStream::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post(route('stream.start', $stream))->assertForbidden();
        $this->assertSame('scheduled', $stream->fresh()->status);
        Event::assertNotDispatched(LiveStreamStarted::class);
    }

    public function test_a_non_member_cannot_end_a_stream(): void
    {
        $stream = LiveStream::factory()->create(['status' => 'live']);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post(route('stream.end', $stream))->assertForbidden();
        $this->assertSame('live', $stream->fresh()->status);
    }

    public function test_a_non_member_cannot_send_broadcast_data(): void
    {
        $stream = LiveStream::factory()->create(['status' => 'live']);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post(route('stream.data', $stream), ['chunk' => 'abc', 'type' => 'video'])
            ->assertForbidden();
    }

    public function test_a_non_member_cannot_open_the_manage_page(): void
    {
        $stream = LiveStream::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('stream.edit', $stream))->assertForbidden();
    }

    public function test_show_renders_the_viewer_page_for_non_members(): void
    {
        $stream = LiveStream::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('stream.show', $stream))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('LiveStreams/View'));
    }

    public function test_show_renders_the_manage_page_for_members(): void
    {
        $stream = LiveStream::factory()->create();
        $member = $this->memberOf($stream->artist);

        $this->actingAs($member)
            ->get(route('stream.show', $stream))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('LiveStreams/Manage'));
    }
}
