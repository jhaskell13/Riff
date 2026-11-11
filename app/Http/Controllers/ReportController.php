<?php

namespace App\Http\Controllers;

use App\Http\Requests\Moderation\ReportStoreRequest;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;

/**
 * One endpoint serves every surface that can raise a report, so the subject
 * arrives as a kind and an id rather than a route parameter.
 */
class ReportController extends Controller
{
    public function store(ReportStoreRequest $request): RedirectResponse
    {
        $this->authorize('create', Report::class);

        $subject = $request->subject();
        $user = $request->user();

        // A second open report on the same subject adds nothing to the
        // queue; answered identically so repeating yourself never fails.
        $alreadyOpen = $subject->reports()
            ->open()
            ->where('reporter_id', $user->id)
            ->exists();

        if (! $alreadyOpen) {
            // Field by field; mass-assigning status or resolved_by_id would
            // let a reporter close their own report.
            $report = new Report($request->safe()->only(['reason', 'details']));
            $report->reportable()->associate($subject);
            $report->reporter()->associate($user);
            $report->save();
        }

        return back();
    }
}
