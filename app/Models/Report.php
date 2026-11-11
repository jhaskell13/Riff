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
use Illuminate\Support\Carbon;

/**
 * One complaint about one artist or post. Only what the reporter wrote is
 * fillable: a request that could mass-assign `status` or `resolved_by_id`
 * would let a reporter close their own report.
 */
/**
 * @property int $id
 * @property string $reportable_type
 * @property int $reportable_id
 * @property int|null $reporter_id
 * @property string $reason
 * @property string|null $details
 * @property ReportStatus $status
 * @property ModerationAction|null $resolution
 * @property int|null $resolved_by_id
 * @property Carbon|null $resolved_at
 * @property string|null $moderator_note
 * @property Carbon|null $created_at
 * @property-read Artist|Post|null $reportable
 * @property-read User|null $reporter
 * @property-read User|null $resolvedBy
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
     * The artist or post complained about, archived artists included -- a
     * moderator has to see what an earlier decision acted on.
     */
    public function reportable(): MorphTo
    {
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

    /** The reason's label; a retired reason shows its raw key instead. */
    public function reasonLabel(): string
    {
        return config('riff.moderation.reasons')[$this->reason] ?? $this->reason;
    }
}
