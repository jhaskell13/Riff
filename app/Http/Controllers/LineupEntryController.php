<?php

namespace App\Http\Controllers;

use App\Models\LineupEntry;
use Illuminate\Http\RedirectResponse;

/**
 * An artist answering for its own slot on someone else's bill.
 */
class LineupEntryController extends Controller
{
    public function confirm(LineupEntry $entry): RedirectResponse
    {
        return $this->answer($entry, LineupEntry::CONFIRMED);
    }

    /**
     * Also how a confirmed artist drops out later: the entry leaves every
     * public surface, and the host sees it declined.
     */
    public function decline(LineupEntry $entry): RedirectResponse
    {
        return $this->answer($entry, LineupEntry::DECLINED);
    }

    private function answer(LineupEntry $entry, string $status): RedirectResponse
    {
        $this->authorize('respond', $entry);

        $entry->forceFill(['status' => $status, 'responded_at' => now()])->save();

        return back();
    }
}
