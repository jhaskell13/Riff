<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The map's "when" control for shows: tonight, this weekend, this week,
 * this month. Computed in the *viewer's* timezone — "this weekend" is the
 * weekend where the person looking is — and returned in UTC for the query.
 *
 * Every window opens at now minus riff.shows.still_on_hours, so tonight's
 * show stays on the map after the doors open, matching Show::upcoming().
 */
final class ShowWindow
{
    public const OPTIONS = [
        'tonight' => 'Tonight',
        'weekend' => 'This weekend',
        'week' => 'Next 7 days',
        'month' => 'Next 30 days',
    ];

    public const DEFAULT = 'month';

    private function __construct(
        public readonly string $name,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        /** The viewer's local date the window was built on, for cache keys. */
        public readonly string $localDate,
        public readonly string $timezone,
    ) {}

    public static function make(string $name, string $timezone, ?CarbonImmutable $now = null): self
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $from = $now->subHours((int) config('riff.shows.still_on_hours'));

        $to = match ($name) {
            'tonight' => $now->endOfDay(),
            'weekend' => $now->dayOfWeekIso === 7 ? $now->endOfDay() : $now->next(CarbonImmutable::SUNDAY)->endOfDay(),
            'week' => $now->addDays(7)->endOfDay(),
            default => $now->addDays(30)->endOfDay(),
        };

        // Before Friday, the weekend starts on Friday.
        if ($name === 'weekend' && $now->dayOfWeekIso < 5) {
            $from = $now->next(CarbonImmutable::FRIDAY)->startOfDay();
        }

        return new self($name, $from->utc(), $to->utc(), $now->toDateString(), $timezone);
    }
}
