<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Arr;

/**
 * The researched pilot-scene dataset (database/seeders/data/pilot-scene.json):
 * real Connecticut acts and venues from public sources, plus the fictional
 * entries that carry every invented state (hidden, archived, reports, posts,
 * listings, pending slots, cancellations). Read its _meta before editing.
 */
final class PilotScene
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        return self::$data ??= json_decode(
            (string) file_get_contents(__DIR__.'/../data/pilot-scene.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return list<array<string, mixed>> */
    public static function acts(): array
    {
        return self::data()['acts'];
    }

    /** @return list<array<string, mixed>> */
    public static function venues(): array
    {
        return self::data()['venues'];
    }

    /** @return list<array<string, mixed>> */
    public static function shows(): array
    {
        return self::data()['shows'];
    }

    /** @return list<array<string, mixed>> */
    public static function listings(): array
    {
        return self::data()['listings'];
    }

    /**
     * Names of the invented acts — the only ones that may carry seeded
     * reports, feed posts, or any other attributed state.
     *
     * @return list<string>
     */
    public static function fictionalActNames(): array
    {
        return array_values(Arr::pluck(
            array_filter(self::acts(), fn (array $act) => $act['real'] === false),
            'name',
        ));
    }
}
