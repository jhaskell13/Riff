<?php

namespace App\Http\Controllers;

use App\Http\Requests\Feed\FeedStoreRequest;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    public function index(): Response
    {
        $posts = Post::query()
            ->published()
            ->with(['user:id,name', 'artist:id,name,image_url'])
            ->latest('published_at')
            ->paginate(15);

        return Inertia::render('Feed', [
            'posts' => $posts,
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
