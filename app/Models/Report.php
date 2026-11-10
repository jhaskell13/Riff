<?php

namespace App\Models;

use App\Support\ModerationAction;
use App\Support\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One complaint about one artist or post.
 *
 * Only what the reporter wrote is fillable. Who filed it, what it is about,
 * and everything about its resolution are set explicitly -- a request that
 * could mass-assign `status` or `resolved_by_id` would let a reporter close
 * their own report.
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    protected $fillable = [
        'reason',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'resolution' => ModerationAction::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * The artist or post complained about. Archived artists and removed
     * posts stay readable here: a moderator has to be able to see what they
     * acted on.
     */
    public function reportable(): MorphTo
    {
        // withTrashed applies only to subjects that soft-delete, so an
        // artist archived by an earlier decision is still readable in the
        // queue instead of appearing as a report about nothing.
        return $this->morphTo()->withTrashed();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ReportStatus::Open);
    }

    /**
     * The label this report's reason was filed under. Reasons retired from
     * the config keep their stored key, which is better shown raw than
     * silently dropped.
     */
    public function reasonLabel(): string
    {
        return config('riff.moderation.reasons')[$this->reason] ?? $this->reason;
    }
}
