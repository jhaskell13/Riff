<?php

use App\Http\Controllers\ArtistBlockController;
use App\Http\Controllers\ArtistFollowController;
use App\Http\Controllers\ArtistPhotoController;
use App\Http\Controllers\ArtistZoneController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\LineupEntryController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\ListingReplyController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SceneController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ShowController;
use App\Http\Controllers\ShowRsvpController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

// Deployment infrastructure may probe /health; keep alongside Laravel's /up.
Route::get('/health', fn () => response('OK'));

// The map is Riff's front door for everyone. The stock Laravel welcome
// page used to answer here; the introduction a first-time visitor needs is
// an overlay on the map itself (Components/Map/WelcomeOverlay.vue), so
// there is no separate landing page to pass through.
Route::redirect('/', '/map')->name('home');

// Map data is public so a first-time visitor can explore before signing up.
// Rate-limited per user or IP; see the "map" limiter.
Route::middleware('throttle:map')->group(function () {
    // The map shell itself is public: a first-time visitor should be able
    // to explore local artists before deciding to sign up.
    Route::get('map', [MapController::class, 'index'])->name('map.index');
    Route::get('map/artists', [MapController::class, 'artists'])->name('map.artists');
    Route::get('map/artists/{artist}', [MapController::class, 'card'])
        ->whereNumber('artist')
        ->name('map.artists.card');
    Route::get('map/shows', [MapController::class, 'shows'])->name('map.shows');
    Route::get('map/venues', [MapController::class, 'venues'])->name('map.venues');
    Route::get('map/listings', [MapController::class, 'listings'])->name('map.listings');
    Route::get('map/places', [MapController::class, 'places'])->name('map.places');
});

Route::middleware(['auth', 'verified'])->group(function () {

    // Feed
    Route::get('feed', [FeedController::class, 'index'])->name('feed.index');
    Route::post('feed', [FeedController::class, 'store'])
        ->middleware('throttle:posts')
        ->name('feed.store');

    // Safety: reporting something, and hiding an artist from your own Riff
    Route::post('reports', [ReportController::class, 'store'])
        ->middleware('throttle:reports')
        ->name('reports.store');

    Route::post('artists/{artist}/block', [ArtistBlockController::class, 'store'])
        ->middleware('throttle:blocks')
        ->name('artists.block');
    Route::delete('artists/{artist}/block', [ArtistBlockController::class, 'destroy'])
        ->middleware('throttle:blocks')
        ->name('artists.unblock');

    // Moderation. Both actions authorize through ReportPolicy, which is the
    // only reader of users.is_moderator.
    Route::prefix('moderation')->group(function () {
        Route::get('/', [ModerationController::class, 'index'])->name('moderation.index');
        Route::post('reports/{report}/resolve', [ModerationController::class, 'resolve'])
            ->name('moderation.resolve');
    });

    // Artist Zone
    Route::post('artists/{artist}/restore', [ArtistZoneController::class, 'restore'])
        ->withTrashed()
        ->name('artists.restore');
    Route::post('artists/{artist}/photo', [ArtistPhotoController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('artists.photo.store');
    Route::delete('artists/{artist}/photo', [ArtistPhotoController::class, 'destroy'])
        ->name('artists.photo.destroy');
    Route::post('artists/{artist}/follow', [ArtistFollowController::class, 'store'])
        ->middleware('throttle:follows')
        ->name('artists.follow');
    Route::delete('artists/{artist}/follow', [ArtistFollowController::class, 'destroy'])
        ->middleware('throttle:follows')
        ->name('artists.unfollow');
    // show is public and registered below, outside this group.
    Route::resource('artists', ArtistZoneController::class)->except(['show']);

    // Venues: established places that host music regularly. The public
    // page is registered below, outside this group, after venues/create.
    Route::get('venues/create', [VenueController::class, 'create'])->name('venues.create');
    Route::post('venues', [VenueController::class, 'store'])
        ->middleware('throttle:venues')
        ->name('venues.store');
    Route::get('venues/{venue}/edit', [VenueController::class, 'edit'])
        ->whereNumber('venue')
        ->name('venues.edit');
    // Edits are throttled too: each address change is a paid geocode and
    // empties the shared map cache.
    Route::match(['put', 'patch'], 'venues/{venue}', [VenueController::class, 'update'])
        ->whereNumber('venue')
        ->middleware('throttle:venues')
        ->name('venues.update');

    // Shows: listed by a host artist's members. The public page is
    // registered below, outside this group.
    Route::get('shows/create', [ShowController::class, 'create'])->name('shows.create');
    Route::post('shows', [ShowController::class, 'store'])
        ->middleware('throttle:shows')
        ->name('shows.store');
    Route::whereNumber('show')->group(function () {
        Route::get('shows/{show}/edit', [ShowController::class, 'edit'])->name('shows.edit');
        Route::match(['put', 'patch'], 'shows/{show}', [ShowController::class, 'update'])
            ->middleware('throttle:shows')
            ->name('shows.update');
        Route::post('shows/{show}/cancel', [ShowController::class, 'cancel'])->name('shows.cancel');
        Route::post('shows/{show}/rsvp', [ShowRsvpController::class, 'store'])
            ->middleware('throttle:follows')
            ->name('shows.rsvp');
        Route::delete('shows/{show}/rsvp', [ShowRsvpController::class, 'destroy'])
            ->middleware('throttle:follows')
            ->name('shows.unrsvp');
    });

    // Listings ("looking for"). The public page is registered below.
    Route::get('listings/create', [ListingController::class, 'create'])->name('listings.create');
    Route::post('listings', [ListingController::class, 'store'])
        ->middleware('throttle:listings')
        ->name('listings.store');
    Route::whereNumber('listing')->group(function () {
        Route::get('listings/{listing}/edit', [ListingController::class, 'edit'])->name('listings.edit');
        Route::match(['put', 'patch'], 'listings/{listing}', [ListingController::class, 'update'])
            ->middleware('throttle:listings')
            ->name('listings.update');
        Route::post('listings/{listing}/renew', [ListingController::class, 'renew'])->name('listings.renew');
        Route::post('listings/{listing}/close', [ListingController::class, 'close'])->name('listings.close');
        Route::post('listings/{listing}/replies', [ListingReplyController::class, 'store'])
            ->middleware('throttle:listing-replies')
            ->name('listings.replies.store');
    });

    // Lineup consent: an artist answering for its own slot on a bill.
    Route::whereNumber('entry')->middleware('throttle:follows')->group(function () {
        Route::post('lineup/{entry}/confirm', [LineupEntryController::class, 'confirm'])->name('lineup.confirm');
        Route::post('lineup/{entry}/decline', [LineupEntryController::class, 'decline'])->name('lineup.decline');
    });

    // Name lookups for the show form's pickers.
    Route::middleware('throttle:map')->prefix('search')->group(function () {
        Route::get('artists', [SearchController::class, 'artists'])->name('search.artists');
        Route::get('venues', [SearchController::class, 'venues'])->name('search.venues');
    });

    // Live Stream broadcast management
    Route::prefix('streams')->group(function () {
        Route::get('/', [StreamController::class, 'index'])->name('streams');
        Route::get('/create', [StreamController::class, 'create'])->name('stream.create');
        Route::post('/', [StreamController::class, 'store'])->name('stream.store');
        Route::get('/{stream}', [StreamController::class, 'show'])->name('stream.show');
        Route::get('/{stream}/manage', [StreamController::class, 'edit'])->name('stream.edit');
        Route::post('/{stream}/data', [StreamController::class, 'sendData'])->name('stream.data');
        Route::post('/{stream}/start', [StreamController::class, 'start'])->name('stream.start');
        Route::post('/{stream}/end', [StreamController::class, 'end'])->name('stream.end');
    });
});

// Artist profiles are public: a shared link must work logged out. These sit
// after the auth group so artists/create and the other zone routes match
// first, and the numeric route sits before the slug route so an old
// /artists/{id} link redirects (slugs are never all digits).
Route::middleware('throttle:map')->group(function () {
    // A distinct parameter name: {artist} here would collide with the slug
    // route below (binding fields are stripped from the URI, so both would
    // key as artists/{artist} and the later one wins).
    Route::get('artists/{artistId}', [ArtistZoneController::class, 'redirectToSlug'])
        ->whereNumber('artistId')
        ->name('artists.show.id');
    Route::get('artists/{artist:slug}', [ArtistZoneController::class, 'show'])
        ->name('artists.show');
    Route::get('venues/{venue:slug}', [VenueController::class, 'show'])
        ->name('venues.show');
    Route::get('shows/{show}', [ShowController::class, 'show'])
        ->whereNumber('show')
        ->name('shows.show');
    Route::get('listings/{listing}', [ListingController::class, 'show'])
        ->whereNumber('listing')
        ->name('listings.show');
    // A scene: a place, optionally narrowed to one genre family. Unknown
    // families 404 at the router.
    Route::get('scenes/{place}/{family?}', [SceneController::class, 'show'])
        ->whereIn('family', array_keys((array) config('riff.scenes.families')))
        ->name('scenes.show');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
