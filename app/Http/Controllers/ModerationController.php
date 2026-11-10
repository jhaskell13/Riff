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
 * The report queue: the whole of Riff's moderation surface. Small on
 * purpose -- a pilot needs one trusted person to see what was reported and
 * do one of four things about it, with a record of who decided what.
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
            // Oldest first while open: a complaint should not wait behind
            // newer ones. Settled reports read as history, newest first.
            ->orderBy('created_at', $status === ReportStatus::Open ? 'asc' : 'desc')
            // A reported post is recognised by who posted it, so the morph
            // carries those two names rather than fetching them per row.
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
     * Carry out a decision and close the complaint. Every other open report
     * about the same subject closes with it: ten people reporting one
     * artist is one decision, not ten.
     */
    public function resolve(ReportResolveRequest $request, Report $report): RedirectResponse
    {
        $this->authorize('resolve', $report);

        $action = $request->action();
        $subject = $report->reportable;

        if ($subject !== null) {
            $action->apply($subject);
        }

        // Stored as the backed values: this array is used for a mass update
        // as well as for the model, and only the model has casts.
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

        // The report being acted on may already have been settled by an
        // earlier decision; record this one against it regardless.
        $report->forceFill($resolution)->save();

        return back();
    }
}
