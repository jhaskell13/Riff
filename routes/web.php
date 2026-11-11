<?php

use App\Http\Controllers\ArtistBlockController;
use App\Http\Controllers\ArtistFollowController;
use App\Http\Controllers\ArtistPhotoController;
use App\Http\Controllers\ArtistZoneController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StreamController;
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
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
