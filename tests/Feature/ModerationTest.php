<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\ModerationAction;
use App\Support\ReportStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The report queue: who may open it, what the four decisions do, and what
 * the record of a decision has to contain afterwards.
 */
class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private function moderator(): User
    {
        return User::factory()->moderator()->create();
    }

    public function test_the_queue_is_closed_to_ordinary_accounts(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('moderation.index'))
            ->assertForbidden();
    }

    public function test_the_queue_is_closed_to_guests(): void
    {
        $this->get(route('moderation.index'))->assertRedirect(route('login'));
    }

    public function test_a_moderator_sees_open_reports_oldest_first(): void
    {
        $older = Report::factory()->create(['created_at' => now()->subDay()]);
        $newer = Report::factory()->create(['created_at' => now()]);
        Report::factory()->resolved()->create();

        $this->actingAs($this->moderator())
            ->get(route('moderation.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Moderation/Index')
                ->where('openCount', 2)
                ->has('reports.data', 2)
                ->where('reports.data.0.id', $older->id)
                ->where('reports.data.1.id', $newer->id));
    }

    public function test_settled_reports_are_their_own_tab(): void
    {
        Report::factory()->create();
        $settled = Report::factory()->resolved()->create();

        $this->actingAs($this->moderator())
            ->get(route('moderation.index', ['status' => 'resolved']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('reports.data', 1)
                ->where('reports.data.0.id', $settled->id)
                ->where('reports.data.0.statusLabel', 'Actioned'));
    }

    public function test_the_queue_shows_what_was_reported(): void
    {
        $artist = Artist::factory()->create(['name' => 'The Reported']);
        Report::factory()->about($artist)->create(['reason' => 'spam', 'details' => 'Selling watches']);

        $this->actingAs($this->moderator())
            ->get(route('moderation.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reports.data.0.subject.kind', 'artist')
                ->where('reports.data.0.subject.title', 'The Reported')
                ->where('reports.data.0.reasonLabel', 'Spam or a scam')
                ->where('reports.data.0.details', 'Selling watches'));
    }

    public function test_hiding_an_artist_takes_their_pin_off_the_map(): void
    {
        $moderator = $this->moderator();
        $artist = Artist::factory()->create(['show_on_map' => true]);
        $report = Report::factory()->about($artist)->create();

        $this->actingAs($moderator)
            ->post(route('moderation.resolve', $report), [
                'action' => ModerationAction::HideArtist->value,
                'note' => 'Selling watches, not music.',
            ])
            ->assertRedirect();

        $this->assertFalse($artist->fresh()->show_on_map);
        $this->assertNull($artist->fresh()->deleted_at);

        $report->refresh();
        $this->assertSame(ReportStatus::Resolved, $report->status);
        $this->assertSame(ModerationAction::HideArtist, $report->resolution);
        $this->assertSame($moderator->id, $report->resolved_by_id);
        $this->assertSame('Selling watches, not music.', $report->moderator_note);
        $this->assertNotNull($report->resolved_at);
    }

    public function test_archiving_an_artist_is_the_recoverable_soft_delete(): void
    {
        $artist = Artist::factory()->create();
        $report = Report::factory()->about($artist)->create();

        $this->actingAs($this->moderator())
            ->post(route('moderation.resolve', $report), ['action' => ModerationAction::ArchiveArtist->value]);

        $this->assertSoftDeleted($artist);
        $this->assertNotNull(Artist::withTrashed()->find($artist->id));
    }

    public function test_removing_a_post_keeps_the_row_but_drops_it_from_the_feed(): void
    {
        $post = Post::factory()->create(['content' => 'Reported content']);
        $report = Report::factory()->about($post)->create();

        $this->actingAs($this->moderator())
            ->post(route('moderation.resolve', $report), ['action' => ModerationAction::RemovePost->value]);

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'removed']);

        $contents = collect($this->actingAs(User::factory()->create())
            ->get(route('feed.index'))
            ->viewData('page')['props']['posts']['data'])
            ->pluck('content');

        $this->assertFalse($contents->contains('Reported content'));
    }

    public function test_dismissing_changes_nothing_but_the_record(): void
    {
        $artist = Artist::factory()->create(['show_on_map' => true]);
        $report = Report::factory()->about($artist)->create();

        $this->actingAs($this->moderator())
            ->post(route('moderation.resolve', $report), ['action' => ModerationAction::Dismiss->value]);

        $this->assertTrue($artist->fresh()->show_on_map);
        $this->assertNull($artist->fresh()->deleted_at);
        $this->assertSame(ReportStatus::Dismissed, $report->fresh()->status);
    }

    /**
     * Ten people reporting one artist is one decision, not ten.
     */
    public function test_resolving_one_report_closes_every_other_about_the_same_subject(): void
    {
        $artist = Artist::factory()->create();
        $reports = Report::factory()->count(3)->about($artist)->create();
        $unrelated = Report::factory()->create();

        $this->actingAs($this->moderator())
            ->post(route('moderation.resolve', $reports->first()), [
                'action' => ModerationAction::HideArtist->value,
            ]);

        foreach ($reports as $report) {
            $this->assertSame(ReportStatus::Resolved, $report->fresh()->status);
            $this->assertSame(ModerationAction::HideArtist, $report->fresh()->resolution);
        }

        $this->assertSame(ReportStatus::Open, $unrelated->fresh()->status);
    }

    public function test_an_action_must_fit_what_was_reported(): void
    {
        $post = Post::factory()->create();
        $report = Report::factory()->about($post)->create();

        $this->actingAs($this->moderator())
            ->post(route('moderation.resolve', $report), [
                'action' => ModerationAction::ArchiveArtist->value,
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame(ReportStatus::Open, $report->fresh()->status);
    }

    public function test_an_unknown_action_is_rejected(): void
    {
        $report = Report::factory()->create();

        $this->actingAs($this->moderator())
            ->post(route('moderation.resolve', $report), ['action' => 'delete_everything'])
            ->assertSessionHasErrors('action');
    }

    public function test_ordinary_accounts_cannot_resolve_reports(): void
    {
        $artist = Artist::factory()->create(['show_on_map' => true]);
        $report = Report::factory()->about($artist)->create();

        $this->actingAs(User::factory()->create())
            ->post(route('moderation.resolve', $report), [
                'action' => ModerationAction::ArchiveArtist->value,
            ])
            ->assertForbidden();

        $this->assertSame(ReportStatus::Open, $report->fresh()->status);
        $this->assertNull($artist->fresh()->deleted_at);
    }

    /**
     * Membership is what lets someone edit an artist; it must not be a way
     * to escape the queue, and moderators are not members of what they act
     * on either.
     */
    public function test_a_moderator_may_act_on_an_artist_they_are_a_member_of(): void
    {
        $moderator = $this->moderator();
        $artist = Artist::factory()->create(['show_on_map' => true]);
        $artist->members()->attach($moderator);
        $report = Report::factory()->about($artist)->create();

        $this->actingAs($moderator)
            ->post(route('moderation.resolve', $report), [
                'action' => ModerationAction::HideArtist->value,
            ])
            ->assertRedirect();

        $this->assertFalse($artist->fresh()->show_on_map);
    }

    public function test_a_report_about_an_archived_artist_is_still_readable_and_closeable(): void
    {
        $artist = Artist::factory()->create();
        $report = Report::factory()->about($artist)->create();
        $artist->delete();

        $this->actingAs($this->moderator())
            ->get(route('moderation.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('reports.data.0.subject.title', $artist->name)
                ->where('reports.data.0.subject.state', 'Archived'));

        $this->actingAs($this->moderator())
            ->post(route('moderation.resolve', $report), ['action' => ModerationAction::Dismiss->value])
            ->assertRedirect();

        $this->assertSame(ReportStatus::Dismissed, $report->fresh()->status);
    }

    public function test_hiding_an_artist_rotates_the_map_cache(): void
    {
        $artist = Artist::factory()->create(['lat' => 41.5, 'lng' => -72.5, 'show_on_map' => true]);
        $report = Report::factory()->about($artist)->create();
        $bounds = ['south' => 41.0, 'north' => 42.0, 'west' => -73.0, 'east' => -72.0];

        $this->getJson(route('map.artists', $bounds))
            ->assertOk()
            ->assertJsonPath('meta.returned', 1);

        $this->actingAs($this->moderator())
            ->post(route('moderation.resolve', $report), [
                'action' => ModerationAction::HideArtist->value,
            ]);

        $this->getJson(route('map.artists', $bounds))
            ->assertOk()
            ->assertJsonPath('meta.returned', 0);
    }

    public function test_moderator_access_is_granted_only_by_the_console_command(): void
    {
        $user = User::factory()->create(['email' => 'mod@example.com']);

        // Nothing in a request can set it, including the profile form.
        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'city' => $user->city,
            'state' => $user->state,
            'country' => $user->country,
            'distance_unit' => 'mi',
            'is_moderator' => true,
        ]);

        $this->assertFalse($user->fresh()->is_moderator);

        $this->artisan('riff:moderator', ['email' => 'mod@example.com'])
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_moderator);

        $this->artisan('riff:moderator', ['email' => 'mod@example.com', '--revoke' => true])
            ->assertSuccessful();

        $this->assertFalse($user->fresh()->is_moderator);
    }

    public function test_the_moderator_command_reports_an_unknown_account(): void
    {
        $this->artisan('riff:moderator', ['email' => 'nobody@example.com'])
            ->assertFailed();
    }
}
