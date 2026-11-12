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

        // Every photo is decoded and re-encoded, which is CPU and memory
        // heavy; a handful a minute is far more than a real artist needs.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // A flood of reports is itself an abuse vector -- it buries the
        // real ones -- so reporting is the tightest limit in the app.
        RateLimiter::for('reports', function (Request $request) {
            return Limit::perMinute(config('riff.moderation.rate_limit_per_minute'))
                ->by($request->user()?->id ?: $request->ip());
        });

        // Blocking is as repeatable as following and costs about as little.
        // Venues are community-submitted and published at once, so both a
        // burst limit and a daily ceiling per account.
        RateLimiter::for('venues', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(config('riff.venues.rate_limit_per_minute'))->by('minute:'.$key),
                Limit::perDay(config('riff.venues.rate_limit_per_day'))->by('day:'.$key),
            ];
        });

        RateLimiter::for('shows', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(config('riff.shows.rate_limit_per_minute'))->by('minute:'.$key),
                Limit::perDay(config('riff.shows.rate_limit_per_day'))->by('day:'.$key),
            ];
        });

        RateLimiter::for('listings', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(config('riff.listings.rate_limit_per_minute'))->by('minute:'.$key),
                Limit::perDay(config('riff.listings.rate_limit_per_day'))->by('day:'.$key),
            ];
        });

        // Each reply is an email to a real person.
        RateLimiter::for('listing-replies', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(config('riff.listings.reply_rate_limit_per_minute'))->by('minute:'.$key),
                Limit::perDay(config('riff.listings.reply_rate_limit_per_day'))->by('day:'.$key),
            ];
        });

        RateLimiter::for('blocks', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Outbound support-link clicks. Public and unauthenticated, so it
        // needs a ceiling -- but a generous one: the counter it feeds
        // answers "do support links get clicked", and throttling a real
        // reader's click would make the answer wrong, not just late.
        RateLimiter::for('support-clicks', function (Request $request) {
            return Limit::perMinute(config('riff.support.click_rate_limit_per_minute'))
                ->by($request->user()?->id ?: $request->ip());
        });

        // The map's data endpoints are public. Panning fires a request
        // only when movement settles, so this ceiling is far above real
        // browsing while still bounding a scraper.
        RateLimiter::for('map', function (Request $request) {
            return Limit::perMinute(config('riff.map.rate_limit_per_minute'))
                ->by($request->user()?->id ?: $request->ip());
        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
