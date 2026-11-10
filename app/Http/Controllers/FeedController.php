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
            // Hide blocked artists' posts. The whereNull keeps artist-less
            // posts: NOT IN drops NULL rows, since NULL compares to nothing.
            ->when($request->user()?->hasBlocks(), fn ($query) => $query
                ->where(fn ($scope) => $scope
                    ->whereNull('artist_id')
                    ->orWhereNotIn('artist_id', fn ($blocks) => $blocks
                        ->select('artist_id')
                        ->from('artist_blocks')
                        ->where('user_id', $request->user()->id))))
            // image_path, not image_url: the latter is an accessor over it.
            ->with(['user:id,name', 'artist:id,name,image_path'])
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
