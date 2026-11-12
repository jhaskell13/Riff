import { computed, reactive, watch } from 'vue';

/**
 * Map filter state, mirrored into the URL so a filtered view can be
 * shared, bookmarked, and reached with the back button.
 *
 * History is rewritten rather than pushed while panning -- a map that
 * added an entry per movement would make the back button useless.
 */

// The scene map shows every kind at once; hiding one is a filter, not a
// mode, so a first visit sees artists, shows and venues together.
export const ALL_KINDS = ['artists', 'shows', 'venues', 'listings'];
export const DEFAULT_WHEN = 'month';

export const DEFAULT_FILTERS = {
    kinds: ALL_KINDS,
    when: DEFAULT_WHEN,
    q: '',
    genres: [],
    types: [],
    radius: null,
    has_music: false,
    has_photo: false,
    followed: false,
    sort: 'distance',
};

const asList = (value) => (value ? value.split(',').filter(Boolean) : []);

export function useMapFilters() {
    // This runs during SSR too, where there is no window; an empty set of
    // params gives the defaults, and the browser re-reads the real URL
    // when the component hydrates.
    const onClient = typeof window !== 'undefined';
    const params = new URLSearchParams(onClient ? window.location.search : '');

    const kindsParam = asList(params.get('kinds')).filter((kind) => ALL_KINDS.includes(kind));

    const filters = reactive({
        ...DEFAULT_FILTERS,
        // Kept in ALL_KINDS order so the URL and comparisons are stable.
        kinds: kindsParam.length ? ALL_KINDS.filter((kind) => kindsParam.includes(kind)) : [...ALL_KINDS],
        when: params.get('when') ?? DEFAULT_WHEN,
        q: params.get('q') ?? '',
        genres: asList(params.get('genres')),
        types: asList(params.get('types')),
        radius: params.get('radius') ? Number(params.get('radius')) : null,
        has_music: params.get('has_music') === '1',
        has_photo: params.get('has_photo') === '1',
        followed: params.get('followed') === '1',
        sort: params.get('sort') ?? 'distance',
    });

    /** Viewport from a shared link, if it carried one. */
    const initialView = params.get('lat') && params.get('lng')
        ? {
            lat: Number(params.get('lat')),
            lng: Number(params.get('lng')),
            zoom: params.get('zoom') ? Number(params.get('zoom')) : 11,
        }
        : null;

    const kindsNarrowed = computed(() => filters.kinds.length < ALL_KINDS.length);
    const includesKind = (kind) => filters.kinds.includes(kind);

    const activeCount = computed(() =>
        (kindsNarrowed.value ? 1 : 0)
        + (filters.when !== DEFAULT_WHEN ? 1 : 0)
        + (filters.q ? 1 : 0)
        + filters.genres.length
        + filters.types.length
        + (filters.radius ? 1 : 0)
        + (filters.has_music ? 1 : 0)
        + (filters.has_photo ? 1 : 0)
        + (filters.followed ? 1 : 0),
    );

    const isFiltered = computed(() => activeCount.value > 0);

    const reset = () => Object.assign(filters, { ...DEFAULT_FILTERS, kinds: [...ALL_KINDS], genres: [], types: [] });

    /**
     * Parameters for /map/shows and /map/venues: genres carry over (a punk
     * filter means punk bills and the rooms hosting them); the artist-only
     * filters don't. The timezone makes "this weekend" the viewer's own.
     */
    const toSceneQuery = () => {
        const query = { when: filters.when };

        if (filters.genres.length) query.genres = filters.genres.join(',');

        try {
            query.tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        } catch {
            // The server falls back to the pilot's timezone.
        }

        return query;
    };

    /**
     * Only the parameters the API cares about, omitting anything left at
     * its default so the URL and the cache key stay short.
     */
    const toQuery = () => {
        const query = {};

        if (filters.q.trim().length >= 2) query.q = filters.q.trim();
        if (filters.genres.length) query.genres = filters.genres.join(',');
        if (filters.types.length) query.types = filters.types.join(',');
        if (filters.radius) query.radius = filters.radius;
        if (filters.has_music) query.has_music = 1;
        if (filters.has_photo) query.has_photo = 1;
        if (filters.followed) query.followed = 1;
        if (filters.sort !== 'distance') query.sort = filters.sort;

        return query;
    };

    /** What the URL carries: the artist query plus the scene's own state. */
    const toUrlQuery = () => {
        const query = toQuery();

        if (kindsNarrowed.value) query.kinds = filters.kinds.join(',');
        if (filters.when !== DEFAULT_WHEN) query.when = filters.when;

        return query;
    };

    // The last viewport written to the URL, so a filter change rewrites
    // the query without discarding where the listener is looking.
    let lastView = initialView;

    const syncUrl = (view) => {
        lastView = view ?? lastView;
        const next = new URLSearchParams(toUrlQuery());

        if (lastView) {
            next.set('lat', lastView.lat.toFixed(4));
            next.set('lng', lastView.lng.toFixed(4));
            next.set('zoom', String(lastView.zoom));
        }

        if (!onClient) {
            return;
        }

        const query = next.toString();
        window.history.replaceState(window.history.state, '', query ? `?${query}` : window.location.pathname);
    };

    watch(filters, () => syncUrl(), { deep: true });

    return { filters, initialView, activeCount, isFiltered, reset, toQuery, toSceneQuery, syncUrl, includesKind };
}
