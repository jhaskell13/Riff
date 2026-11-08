<?php

namespace App\Http\Controllers;

use App\Events\LiveStreamDataSent;
use App\Events\LiveStreamStarted;
use App\Http\Requests\Streams\StreamStoreRequest;
use App\Models\Artist;
use App\Models\LiveStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class StreamController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('LiveStreams/Index');
    }

    public function create(): Response
    {
        return Inertia::render('LiveStreams/Broadcast', [
            'artists' => Auth::user()->artists()->select('artists.id', 'artists.name')->get(),
        ]);
    }

    public function store(StreamStoreRequest $request): RedirectResponse
    {
        $artist = Artist::findOrFail($request->validated('artist_id'));

        $this->authorize('create', [LiveStream::class, $artist]);

        $stream = LiveStream::create($request->validated());

        return redirect()->route('stream.edit', $stream);
    }

    public function start(Request $request, LiveStream $stream): JsonResponse
    {
        $this->authorize('manage', $stream);

        $stream->update([
            'status' => 'live',
            'started_at' => now(),
        ]);

        broadcast(new LiveStreamStarted($stream->id));

        return response()->json([
            'message' => 'Stream started.',
        ]);
    }

    public function end(Request $request, LiveStream $stream): JsonResponse
    {
        $this->authorize('manage', $stream);

        $stream->update([
            'status' => 'ended',
            'ended_at' => now(),
        ]);

        return response()->json([
            'message' => 'Stream ended.',
        ]);
    }

    public function sendData(Request $request, LiveStream $stream): JsonResponse
    {
        $this->authorize('manage', $stream);

        $request->validate([
            // ~1.5MB of base64 per chunk; broadcasting video through HTTP
            // POSTs is a prototype transport, but it should not accept
            // arbitrarily large payloads while it exists.
            'chunk' => ['required', 'string', 'max:1500000'],
            'type' => ['required', 'in:video,audio'],
        ]);

        broadcast(new LiveStreamDataSent(
            $stream->id,
            $request->chunk,
            $request->type,
            microtime(true)
        ));

        return response()->json(['status' => 'sent successfully']);
    }

    public function show(LiveStream $stream): Response
    {
        if (Auth::user()->can('manage', $stream)) {
            return Inertia::render('LiveStreams/Manage', [
                'stream' => $stream,
            ]);
        }

        $this->authorize('view', $stream);

        return Inertia::render('LiveStreams/View', [
            'stream' => $stream,
        ]);
    }

    public function edit(LiveStream $stream): Response
    {
        $this->authorize('manage', $stream);

        return Inertia::render('LiveStreams/Manage', [
            'stream' => $stream,
        ]);
    }
}
