<?php

namespace App\Http\Controllers;

use App\Http\Requests\Moderation\ReportStoreRequest;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;

/**
 * Raising a report, from wherever it was raised. One endpoint serves the
 * map card, the artist profile, and the feed, so the subject arrives as a
 * kind and an id rather than as a route parameter.
 */
class ReportController extends Controller
{
    public function store(ReportStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', Report::class);

        $subject = $request->subject();
        $user = $request->user();

        // Reporting the same thing twice while the first report is still
        // open adds nothing to the queue. Answered the same way either way:
        // a reporter learns nothing from the difference, and repeating
        // yourself should not feel like a failure.
        $alreadyOpen = $subject->reports()
            ->open()
            ->where('reporter_id', $user->id)
            ->exists();

        if (! $alreadyOpen) {
            // Built field by field: a request that could set status or
            // resolved_by_id would let a reporter close their own report.
            $report = new Report($request->safe()->only(['reason', 'details']));
            $report->reportable()->associate($subject);
            $report->reporter()->associate($user);
            $report->save();
        }

        return back();
    }
}
