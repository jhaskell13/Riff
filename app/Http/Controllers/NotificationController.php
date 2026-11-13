<?php

namespace App\Http\Controllers;

use App\Services\Notifier;
use App\Support\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bell: what Riff has told you, and what you want to be told.
 *
 * Reading is always scoped to the signed-in account -- notifications are
 * fetched through `$request->user()->notifications`, never looked up by id
 * first, so there is no route here that could be pointed at somebody
 * else's bell.
 */
class NotificationController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->paginate((int) config('riff.notifications.per_page'))
            ->withQueryString();

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications->getCollection()
                ->map(fn ($notification) => $this->present($notification))
                ->values()
                ->all(),
            'nextPage' => $notifications->hasMorePages() ? $notifications->currentPage() + 1 : null,
            'unreadCount' => $user->unreadNotifications()->count(),
            'preferences' => $this->notifier->preferencesFor($user),
            // Email is a separate question from the bell: someone can
            // happily keep notifications and want nothing in their inbox.
            'digest' => [
                'frequency' => $user->digest_frequency,
                'options' => collect((array) config('riff.digest.frequencies'))
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
                'lastSentAt' => $user->digest_sent_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * The bell's dropdown: the most recent few, fetched when it opens
     * rather than shared with every page. The badge count is cheap enough
     * to share; a list of titles and bodies is not.
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'notifications' => $user->notifications()
                ->limit((int) config('riff.notifications.bell_limit'))
                ->get()
                ->map(fn ($notification) => $this->present($notification))
                ->values()
                ->all(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /** Mark one read. Scoped to the owner by the relation, not by id. */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->whereKey($notification)->first()?->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    /**
     * Which kinds this account wants. Validated against the vocabulary, so
     * the form can only ever submit what exists, and `required` kinds are
     * ignored however they arrive.
     */
    public function preferences(Request $request): RedirectResponse
    {
        $types = NotificationType::cases();

        $validated = $request->validate([
            // present, not required: a form of toggles submits an empty
            // array when everything is switched off, and `required`
            // rejected exactly the person who wanted silence.
            'preferences' => ['present', 'array'],
            ...collect($types)->mapWithKeys(fn ($type) => [
                'preferences.'.$type->value => ['sometimes', 'boolean'],
            ])->all(),
        ]);

        $preferences = [];

        foreach ($types as $type) {
            if ($type->config()['required'] ?? false) {
                continue;
            }

            $preferences[$type->value] = (bool) ($validated['preferences'][$type->value] ?? false);
        }

        $digest = $request->validate([
            'digest_frequency' => ['sometimes', Rule::in(array_keys((array) config('riff.digest.frequencies')))],
        ]);

        $request->user()->forceFill([
            'notification_preferences' => $preferences,
            ...array_key_exists('digest_frequency', $digest)
                ? ['digest_frequency' => $digest['digest_frequency']]
                : [],
        ])->save();

        return back();
    }

    /**
     * The payload the bell renders. Flattened out of the stored `data` so
     * the client never reaches into a nested blob, and so a notification
     * written by an older version of a class still renders.
     *
     * @return array<string, mixed>
     */
    private function present(object $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? null,
            'title' => $data['title'] ?? 'Something happened on Riff',
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
            'icon' => $data['icon'] ?? 'notifications',
            // "and 4 more like it": how many folded into this one.
            'count' => (int) ($data['count'] ?? 1),
            'artist' => $data['artist'] ?? null,
            'readAt' => $notification->read_at?->toIso8601String(),
            'createdAt' => $notification->created_at?->toIso8601String(),
        ];
    }
}
