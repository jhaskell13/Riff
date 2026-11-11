<?php

namespace App\Http\Controllers;

use App\Http\Requests\Moderation\ReportResolveRequest;
use App\Http\Resources\ReportResource;
use App\Models\Post;
use App\Models\Report;
use App\Support\ReportStatus;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The report queue: the whole of Riff's moderation surface, sized for a
 * pilot with one trusted moderator.
 */
class ModerationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Report::class);

        $status = ReportStatus::from($request->validate([
            'status' => ['nullable', Rule::in(ReportStatus::values())],
        ])['status'] ?? ReportStatus::Open->value);

        $reports = Report::query()
            ->where('status', $status)
            // Open reads as a queue (oldest first), settled as history.
            ->orderBy('created_at', $status === ReportStatus::Open ? 'asc' : 'desc')
            ->with([
                'reportable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    Post::class => ['artist:id,name', 'user:id,name'],
                ]),
                'reporter:id,name',
                'resolvedBy:id,name',
            ])
            ->paginate((int) config('riff.moderation.queue_page_size'))
            ->withQueryString();

        return Inertia::render('Moderation/Index', [
            'reports' => ReportResource::collection($reports),
            'status' => $status->value,
            'tabs' => collect(ReportStatus::cases())
                ->map(fn (ReportStatus $case) => [
                    'value' => $case->value,
                    'label' => $case->label(),
                ])
                ->all(),
            'openCount' => Report::query()->open()->count(),
        ]);
    }

    /**
     * Carry out a decision. Every other open report about the same subject
     * closes with it: ten reports on one artist are one decision, not ten.
     */
    public function resolve(ReportResolveRequest $request, Report $report): RedirectResponse
    {
        $this->authorize('resolve', $report);

        $action = $request->action();
        $subject = $report->reportable;

        if ($subject !== null) {
            $action->apply($subject);
        }

        // Backed values: this array also feeds a mass update, which has no
        // casts.
        $resolution = [
            'status' => $action->status()->value,
            'resolution' => $action->value,
            'resolved_by_id' => $request->user()->id,
            'resolved_at' => now(),
            'moderator_note' => $request->validated('note'),
        ];

        Report::query()
            ->open()
            ->where('reportable_type', $report->reportable_type)
            ->where('reportable_id', $report->reportable_id)
            ->update($resolution);

        // Recorded even if an earlier decision already settled this one.
        $report->forceFill($resolution)->save();

        return back();
    }
}
