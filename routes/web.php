<?php

use App\Http\Controllers\ArtistBlockController;
use App\Http\Controllers\ArtistFollowController;
use App\Http\Controllers\ArtistPhotoController;
use App\Http\Controllers\ArtistSupportLinkController;
use App\Http\Controllers\ArtistZoneController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DigestController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\LineupEntryController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\ListingReplyController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SceneController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ShowController;
use App\Http\Controllers\ShowRsvpController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\VenueController;
use App\Http\Controllers\WallModerationController;
use App\Http\Controllers\WallPostController;
use App\Http\Controllers\WallThreadController;
use App\Http\Controllers\YourSceneController;
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

    // The bell. Reading is scoped to the signed-in account by the
    // relation, so there is no route here that can be pointed at someone
    // else's notifications.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    // Fetched when the bell opens, not shared with every page: a count is
    // cheap, a list of titles and bodies is not.
    Route::get('notifications/recent', [NotificationController::class, 'recent'])
        ->name('notifications.recent');
    Route::post('notifications/read', [NotificationController::class, 'readAll'])->name('notifications.readAll');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('notifications.read');
    Route::match(['put', 'patch'], 'notifications/preferences', [NotificationController::class, 'preferences'])
        ->name('notifications.preferences');

    // "Your scene": the signed-in home. Posts from the artists you follow,
    // when they next play, and what's on around you -- by date and
    // distance, never by engagement.
    Route::get('scene', [YourSceneController::class, 'index'])->name('scene.home');

    // The old global feed. Kept deliberately (owner decision, November
    // 2026): it is the starting point for a future digest of followed-entity
    // activity, and is demoted to the end of the nav rather than deleted.
    // Do not extend it in its current form -- a global timeline is what
    // ROADMAP principle 2 rules out, and scene.home is the replacement.
    Route::get('feed', [FeedController::class, 'index'])->name('feed.index');
    Route::post('feed', [FeedController::class, 'store'])
        ->middleware('throttle:posts')
        ->name('feed.store');

    // Artist walls: posting on an artist's page, in one of two voices.
    // Which voice you get is decided by policy, not by the request.
    Route::post('artists/{artist}/posts', [WallPostController::class, 'store'])
        ->whereNumber('artist')
        ->middleware('throttle:wall-posts')
        ->name('wall.posts.store');
    Route::delete('posts/{post}', [WallPostController::class, 'destroy'])
        ->whereNumber('post')
        ->name('wall.posts.destroy');

    Route::whereNumber('post')->group(function () {
        Route::post('posts/{post}/comments', [CommentController::class, 'storeOnPost'])
            ->middleware('throttle:wall-comments')
            ->name('wall.comments.store');
        Route::post('posts/{post}/like', [ReactionController::class, 'likePost'])
            ->middleware('throttle:follows')
            ->name('wall.posts.like');
        Route::delete('posts/{post}/like', [ReactionController::class, 'unlikePost'])
            ->middleware('throttle:follows')
            ->name('wall.posts.unlike');
    });

    // Artist-level moderation: members keeping their own page tidy.
    // Hide and unhide only -- nothing here deletes or takes anything off
    // Riff, which stays a platform moderator's decision reached by report.
    Route::middleware('throttle:wall-moderation')->group(function () {
        Route::post('posts/{post}/hide', [WallModerationController::class, 'hidePost'])
            ->whereNumber('post')->name('wall.posts.hide');
        Route::delete('posts/{post}/hide', [WallModerationController::class, 'unhidePost'])
            ->whereNumber('post')->name('wall.posts.unhide');
        Route::post('comments/{comment}/hide', [WallModerationController::class, 'hideComment'])
            ->whereNumber('comment')->name('wall.comments.hide');
        Route::delete('comments/{comment}/hide', [WallModerationController::class, 'unhideComment'])
            ->whereNumber('comment')->name('wall.comments.unhide');
        Route::post('artists/{artist}/wall-bans', [WallModerationController::class, 'ban'])
            ->whereNumber('artist')->name('wall.bans.store');
        Route::delete('artists/{artist}/wall-bans/{user}', [WallModerationController::class, 'unban'])
            ->whereNumber(['artist', 'user'])->name('wall.bans.destroy');
    });

    Route::whereNumber('comment')->group(function () {
        Route::delete('comments/{comment}', [CommentController::class, 'destroy'])
            ->name('wall.comments.destroy');
        Route::post('comments/{comment}/like', [ReactionController::class, 'likeComment'])
            ->middleware('throttle:follows')
            ->name('wall.comments.like');
        Route::delete('comments/{comment}/like', [ReactionController::class, 'unlikeComment'])
            ->middleware('throttle:follows')
            ->name('wall.comments.unlike');
    });

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
    // Where this artist gets paid. Members only; the public click counter
    // is registered below, outside this group, because a guest following a
    // support link is exactly what the section is for.
    Route::match(['put', 'patch'], 'artists/{artist}/support', [ArtistSupportLinkController::class, 'update'])
        ->whereNumber('artist')
        ->middleware('throttle:posts')
        ->name('artists.support.update');

    Route::post('artists/{artist}/follow', [ArtistFollowController::class, 'store'])
        ->middleware('throttle:follows')
        ->name('artists.follow');
    // Quiet from one artist without unfollowing them.
    Route::match(['put', 'patch'], 'artists/{artist}/follow/notify', [ArtistFollowController::class, 'notify'])
        ->middleware('throttle:follows')
        ->name('artists.follow.notify');
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
        // The show's thread: "who's going?", "what time are you on?".
        // Same controller and same rules as a wall reply -- the subject
        // answers for itself through App\Contracts\Commentable.
        Route::post('shows/{show}/comments', [CommentController::class, 'storeOnShow'])
            ->middleware('throttle:wall-comments')
            ->name('shows.comments.store');
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
// Counting an outbound click on a support link. Public on purpose: the
// artist page works logged out, so support has to as well. POST, so it
// never collides with the slug route below.
Route::post('artists/{artist}/support/{link}/click', [ArtistSupportLinkController::class, 'click'])
    ->whereNumber(['artist', 'link'])
    ->middleware('throttle:support-clicks')
    ->name('artists.support.click');

// More of a thread, on demand: "show more comments" and "see all replies".
// Public, because a guest reading a shared artist link must be able to read
// the whole conversation. Same public-read class as the map.
Route::middleware('throttle:map')->group(function () {
    Route::get('posts/{post}/comments', [WallThreadController::class, 'comments'])
        ->whereNumber('post')
        ->name('wall.comments.index');
    Route::get('shows/{show}/comments', [WallThreadController::class, 'showComments'])
        ->whereNumber('show')
        ->name('shows.comments.index');
    Route::get('comments/{comment}/replies', [WallThreadController::class, 'replies'])
        ->whereNumber('comment')
        ->name('wall.replies.index');
});

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

// Stopping the digest. Signed, and outside the auth group on purpose:
// requiring a sign-in before someone can stop email is how complaints
// become spam reports.
Route::get('digest/unsubscribe/{user}', [DigestController::class, 'unsubscribe'])
    ->middleware('signed')
    ->whereNumber('user')
    ->name('digest.unsubscribe');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
