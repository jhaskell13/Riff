<?php

use App\Http\Controllers\ArtistFollowController;
use App\Http\Controllers\ArtistPhotoController;
use App\Http\Controllers\ArtistZoneController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StreamController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Deployment infrastructure may probe /health; keep alongside Laravel's /up.
Route::get('/health', fn () => response('OK'));

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

// Map data is public so a first-time visitor can explore before signing up.
// Rate-limited per user or IP; see the "map" limiter.
Route::middleware('throttle:map')->group(function () {
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

    // Artist Map (the shell; its data endpoints are public, below)
    Route::get('map', [MapController::class, 'index'])->name('map.index');

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
    Route::resource('artists', ArtistZoneController::class);

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

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
