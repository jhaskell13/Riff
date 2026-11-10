<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\ReportStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filing a report: who may, what they may say, and what the request is not
 * allowed to decide on a moderator's behalf.
 */
class ReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_listener_can_report_an_artist(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        $this->actingAs($user)
            ->post(route('reports.store'), [
                'type' => 'artist',
                'id' => $artist->id,
                'reason' => 'impersonation',
                'details' => 'This is my band, not theirs.',
            ])
            ->assertRedirect();

        $report = Report::sole();

        $this->assertSame($artist->id, $report->reportable_id);
        $this->assertSame(Artist::class, $report->reportable_type);
        $this->assertSame($user->id, $report->reporter_id);
        $this->assertSame('impersonation', $report->reason);
        $this->assertSame(ReportStatus::Open, $report->status);
        $this->assertNull($report->resolved_at);
    }

    public function test_a_listener_can_report_a_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)
            ->post(route('reports.store'), [
                'type' => 'post',
                'id' => $post->id,
                'reason' => 'spam',
            ])
            ->assertRedirect();

        $this->assertSame(Post::class, Report::sole()->reportable_type);
    }

    public function test_guests_cannot_report(): void
    {
        $artist = Artist::factory()->create();

        $this->post(route('reports.store'), [
            'type' => 'artist',
            'id' => $artist->id,
            'reason' => 'spam',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_the_reason_must_be_one_riff_offers(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        $this->actingAs($user)
            ->post(route('reports.store'), [
                'type' => 'artist',
                'id' => $artist->id,
                'reason' => 'i just dont like them',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_reporting_something_that_is_gone_reads_as_a_validation_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('reports.store'), [
                'type' => 'artist',
                'id' => 9999,
                'reason' => 'spam',
            ])
            ->assertSessionHasErrors('id');
    }

    public function test_details_are_bounded(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        $this->actingAs($user)
            ->post(route('reports.store'), [
                'type' => 'artist',
                'id' => $artist->id,
                'reason' => 'other',
                'details' => str_repeat('a', config('riff.moderation.max_details') + 1),
            ])
            ->assertSessionHasErrors('details');
    }

    public function test_reporting_the_same_thing_twice_does_not_queue_it_twice(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($user)->post(route('reports.store'), [
                'type' => 'artist',
                'id' => $artist->id,
                'reason' => 'spam',
            ])->assertRedirect();
        }

        $this->assertDatabaseCount('reports', 1);
    }

    public function test_two_listeners_reporting_one_artist_are_two_reports(): void
    {
        $artist = Artist::factory()->create();

        foreach (User::factory()->count(2)->create() as $user) {
            $this->actingAs($user)->post(route('reports.store'), [
                'type' => 'artist',
                'id' => $artist->id,
                'reason' => 'hate',
            ]);
        }

        $this->assertDatabaseCount('reports', 2);
    }

    /**
     * The reporter decides what they are complaining about and nothing
     * else: a request that could set status or resolved_by_id would let
     * them close their own report, or sign it as a moderator.
     */
    public function test_a_reporter_cannot_decide_the_outcome(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        $this->actingAs($user)->post(route('reports.store'), [
            'type' => 'artist',
            'id' => $artist->id,
            'reason' => 'spam',
            'status' => 'resolved',
            'resolution' => 'dismiss',
            'resolved_by_id' => $user->id,
            'reporter_id' => 9999,
            'moderator_note' => 'Nothing to see here',
        ]);

        $report = Report::sole();

        $this->assertSame(ReportStatus::Open, $report->status);
        $this->assertNull($report->resolution);
        $this->assertNull($report->resolved_by_id);
        $this->assertNull($report->moderator_note);
        $this->assertSame($user->id, $report->reporter_id);
    }

    public function test_reporting_is_rate_limited_per_user(): void
    {
        $user = User::factory()->create();
        $artists = Artist::factory()->count(config('riff.moderation.rate_limit_per_minute') + 1)->create();

        foreach ($artists as $index => $artist) {
            $response = $this->actingAs($user)->post(route('reports.store'), [
                'type' => 'artist',
                'id' => $artist->id,
                'reason' => 'spam',
            ]);

            if ($index < config('riff.moderation.rate_limit_per_minute')) {
                $response->assertRedirect();
            } else {
                $response->assertTooManyRequests();
            }
        }

        $this->assertDatabaseCount('reports', config('riff.moderation.rate_limit_per_minute'));
    }
}
