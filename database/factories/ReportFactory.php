<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\Report;
use App\Models\User;
use App\Support\ModerationAction;
use App\Support\ReportStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reportable_type' => Artist::class,
            'reportable_id' => Artist::factory(),
            'reporter_id' => User::factory(),
            'reason' => fake()->randomElement(array_keys(config('riff.moderation.reasons'))),
            'details' => fake()->sentence(),
            'status' => ReportStatus::Open,
        ];
    }

    /**
     * Already settled, as the history tab shows it.
     */
    public function resolved(ModerationAction $action = ModerationAction::HideArtist): static
    {
        return $this->state(fn () => [
            'status' => $action->status(),
            'resolution' => $action,
            'resolved_by_id' => User::factory()->moderator(),
            'resolved_at' => now(),
            'moderator_note' => fake()->sentence(),
        ]);
    }

    /**
     * About the given artist or post instead of a fresh artist.
     */
    public function about(object $subject): static
    {
        return $this->state(fn () => [
            'reportable_type' => $subject::class,
            'reportable_id' => $subject->getKey(),
        ]);
    }
}
