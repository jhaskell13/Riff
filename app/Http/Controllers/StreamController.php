<?php

namespace App\Http\Controllers;

use App\Events\LiveStreamDataSent;
use App\Events\LiveStreamStarted;
use App\Models\LiveStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StreamController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('LiveStreams/Index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('LiveStreams/Broadcast');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'artist_id' => 'required',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $stream = LiveStream::create([
            'artist_id' => $request->artist_id,
            'title' => $request->title,
            'description' => $request->description,
        ]);

        return redirect()->route('stream.edit', ['stream' => $stream->id]);
    }

    public function start(Request $request, LiveStream $stream): JsonResponse
    {
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
        $request->validate([
            'chunk' => 'required|string',
            'type' => 'required|in:video,audio',
        ]);

        broadcast(new LiveStreamDataSent(
            $stream->id,
            $request->chunk,
            $request->type,
            microtime(true)
        ));

        return response()->json(['status' => 'sent successfully']);
    }

    /**
     * Display the specified resource.
     */
    public function show(LiveStream $stream): Response
    {
        if (auth()->user()->isMemberOf($stream->artist)) {
            return Inertia::render('LiveStreams/Manage', [
                'stream' => $stream,
            ]);
        }

        return Inertia::render('LiveStreams/View', [
            'stream' => $stream,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(LiveStream $stream): Response
    {
        return Inertia::render('LiveStreams/Manage', [
            'stream' => $stream,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
