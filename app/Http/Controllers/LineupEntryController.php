<?php

namespace App\Http\Controllers;

use App\Models\LineupEntry;
use App\Notifications\ShowAnnounced;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;

/**
 * An artist answering for its own slot on someone else's bill.
 */
class LineupEntryController extends Controller
{
    public function __construct(private Notifier $notifier) {}

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

        // Confirming is the moment this act joins a public bill, so it is
        // the moment its own fans can be told. The host's announcement
        // when the show was listed could not name them: they had not
        // agreed yet.
        if ($status === LineupEntry::CONFIRMED
            && $entry->artist !== null
            && $entry->show !== null
            && $entry->show->listed()->whereKey($entry->show->id)->exists()) {
            $entry->show->load('venue');

            $this->notifier->send(
                $entry->artist->followers()->get(),
                (new ShowAnnounced($entry->show, $entry->artist))->causedBy(request()->user()),
            );
        }

        return back();
    }
}
