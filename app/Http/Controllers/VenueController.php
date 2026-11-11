<?php

namespace App\Http\Controllers;

use App\Http\Requests\Venues\VenueRequest;
use App\Http\Resources\VenueResource;
use App\Models\Venue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Established places that host music regularly. Submitted by the
 * community, published at once, moderated through reports.
 */
class VenueController extends Controller
{
    public function create(): Response
    {
        $this->authorize('create', Venue::class);

        return Inertia::render('Venues/Create', $this->formOptions());
    }

    public function store(VenueRequest $request): RedirectResponse
    {
        $this->authorize('create', Venue::class);

        $venue = $request->user()->submittedVenues()->create($request->validated());

        return to_route('venues.show', $venue);
    }

    /**
     * The public venue page. Old slugs resolve (HasHistoricalSlugs) and are
     * redirected to the current one.
     */
    public function show(Request $request, Venue $venue): Response|RedirectResponse
    {
        $this->authorize('view', $venue);

        $requested = $request->route()->originalParameters()['venue'] ?? null;

        if ($requested !== null && $requested !== $venue->slug) {
            return redirect()->route('venues.show', $venue, 301);
        }

        $user = $request->user();

        return Inertia::render('Venues/Show', [
            'venue' => VenueResource::make($venue)->resolve($request),
            'canEdit' => $user?->can('update', $venue) ?? false,
            'canReport' => $user !== null,
        ]);
    }

    public function edit(Venue $venue): Response
    {
        $this->authorize('update', $venue);

        return Inertia::render('Venues/Edit', [
            // The submitter's own form, so the raw street is fine here.
            'venue' => $venue->only([
                'id', 'slug', 'name', 'kind', 'address_visibility', 'street_address',
                'city', 'state', 'country', 'age_policy', 'website', 'description',
            ]),
            ...$this->formOptions(),
        ]);
    }

    public function update(VenueRequest $request, Venue $venue): RedirectResponse
    {
        $this->authorize('update', $venue);

        $venue->update($request->validated());

        return to_route('venues.show', $venue);
    }

    /**
     * The vocabulary both forms offer, straight from config, so a form can
     * never offer what validation rejects.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $options = fn (array $map) => collect($map)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values();

        return [
            'kinds' => $options((array) config('riff.venues.kinds')),
            'agePolicies' => $options((array) config('riff.venues.age_policies')),
            'privateByDefault' => array_values((array) config('riff.venues.private_by_default')),
        ];
    }
}
