<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            // Shared once for every surface that raises a report, so the
            // form always offers exactly what validation accepts.
            'reportReasons' => $request->user() === null
                ? []
                : collect((array) config('riff.moderation.reasons'))
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
            // The bell's badge, on every page. One indexed count against
            // notifications_unread_index; lazily evaluated so a partial
            // reload that does not ask for it does not pay for it.
            'notifications' => fn () => $request->user() === null ? null : [
                'unread' => $request->user()->unreadNotifications()->count(),
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }
}
