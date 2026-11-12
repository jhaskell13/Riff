<?php

namespace App\Http\Controllers;

use App\Models\Place;
use App\Services\SceneView;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A local scene: /scenes/hartford-ct (everything) or /scenes/hartford-ct/punk
 * (one genre family). Public and shareable — the link someone texts a
 * friend.
 */
class SceneController extends Controller
{
    public function show(Request $request, SceneView $scenes, Place $place, ?string $family = null): Response
    {
        return Inertia::render('Scenes/Show', [
            'scene' => $scenes->build($place, $family, $request->user()),
            'canFollow' => $request->user() !== null,
        ]);
    }
}
