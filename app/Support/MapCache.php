<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A version token that prefixes every cached map result. Changing it
 * orphans all existing entries at once (they then age out on their TTL),
 * which is far simpler and cheaper than finding which cached viewports an
 * edited artist appeared in.
 *
 * Bumped by Artist model events and by GeocodeAddress, which writes
 * coordinates quietly and would otherwise slip past the model events.
 */
final class MapCache
{
    private const KEY = 'riff.map.version';

    public static function version(): string
    {
        return Cache::rememberForever(self::KEY, fn () => Str::random(12));
    }

    public static function bump(): void
    {
        Cache::forever(self::KEY, Str::random(12));
    }
}
