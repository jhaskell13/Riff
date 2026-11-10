<?php

namespace App\Http\Controllers;

use App\Http\Requests\Feed\FeedStoreRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    public function index(Request $request): Response
    {
        $posts = Post::query()
            ->published()
            // Posts from an artist this listener has blocked are gone from
            // their feed. A post with no artist behind it belongs to nobody
            // blockable, and `NOT IN` would quietly drop those rows, since
            // a comparison with NULL is never true.
            ->when($request->user()?->hasBlocks(), fn ($query) => $query
                ->where(fn ($scope) => $scope
                    ->whereNull('artist_id')
                    ->orWhereNotIn('artist_id', fn ($blocks) => $blocks
                        ->select('artist_id')
                        ->from('artist_blocks')
                        ->where('user_id', $request->user()->id))))
            ->with(['user:id,name', 'artist:id,name,image_url'])
            ->latest('published_at')
            ->latest('id')
            ->paginate(15);

        return Inertia::render('Feed', [
            'posts' => PostResource::collection($posts),
        ]);
    }

    public function store(FeedStoreRequest $request): RedirectResponse
    {
        $request->user()->posts()->create([
            ...$request->validated(),
            'status' => 'published',
            'published_at' => now(),
        ]);

        return to_route('feed.index');
    }
}
