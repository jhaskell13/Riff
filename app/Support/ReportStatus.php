<?php

namespace App\Support;

/**
 * Where a report stands. Open is the queue; the other two are both "a
 * moderator has looked at this", kept apart so the audit trail shows
 * whether the complaint was acted on or found groundless.
 */
enum ReportStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Actioned',
            self::Dismissed => 'Dismissed',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
