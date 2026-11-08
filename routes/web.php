<?php

use App\Http\Controllers\ArtistZoneController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StreamController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/health', fn () => 200);

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth'])->group(function () {

    // Feed
    Route::resource('feed', FeedController::class)->middleware(['verified']);

    // Artist Map
    Route::get('map', MapController::class)->name('map.index');

    // Artist Zone
    Route::resource('artists', ArtistZoneController::class);

    // Live Stream broadcast management
    Route::prefix('streams')->group(function () {
        Route::get('/', [StreamController::class, 'index'])->name('streams');
        Route::get('/create', [StreamController::class, 'create'])->name('stream.create');
        Route::post('/store', [StreamController::class, 'store'])->name('stream.store');
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
