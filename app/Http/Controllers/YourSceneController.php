<?php

namespace App\Http\Controllers;

use App\Http\Resources\ShowCardResource;
use App\Services\YourScene;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Your scene": the signed-in home, and what the nav's old Feed slot now
 * points at.
 *
 * The global feed is **not** deleted -- it keeps its route, its controller
 * and its page, demoted to the end of the nav, because the owner wants to
 * come back to it as a digest of followed-entity activity (artists,
 * venues, genres, scenes). It must not be *extended* in its current form:
 * a global timeline is what ROADMAP principle 2 rules out, and this page
 * is the replacement.
 *
 * A guest's home is the map; `/` already redirects there.
 */
class YourSceneController extends Controller
{
    public function __construct(private YourScene $scene) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $page = max(1, min((int) $request->integer('page', 1), 1000));

        $data = $this->scene->build($user, $page);

        return Inertia::render('Scene/Home', [
            'updates' => $data['updates'],
            // Rendered through the same card the map, artist pages and
            // scene pages use, so a show reads identically everywhere.
            'shows' => ShowCardResource::collection($data['shows'])->resolve($request),
            'nearby' => $data['nearby'],
            'followingCount' => $data['followingCount'],
            'area' => $data['area'],
        ]);
    }
}
