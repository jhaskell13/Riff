<?php

namespace App\Providers;

use App\Contracts\EventProvider;
use App\Services\Events\TicketmasterEvents;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            EventProvider::class,
            TicketmasterEvents::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Post creation is the easiest surface to spam; keep it modest.
        RateLimiter::for('posts', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Following is a one-tap action a listener may repeat while
        // browsing the map, so the ceiling is high enough not to be felt
        // while still bounding scripted follow/unfollow churn.
        RateLimiter::for('follows', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
