<?php

namespace App\Support;

/**
 * Open is the queue; Resolved and Dismissed both mean "a moderator looked",
 * kept apart so the record shows whether the complaint was acted on.
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
