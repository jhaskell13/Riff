import { computed, reactive, watch } from 'vue';

/**
 * Map filter state, mirrored into the URL so a filtered view can be
 * shared, bookmarked, and reached with the back button.
 *
 * History is rewritten rather than pushed while panning -- a map that
 * added an entry per movement would make the back button useless.
 */

export const DEFAULT_FILTERS = {
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

    const filters = reactive({
        ...DEFAULT_FILTERS,
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

    const activeCount = computed(() =>
        (filters.q ? 1 : 0)
        + filters.genres.length
        + filters.types.length
        + (filters.radius ? 1 : 0)
        + (filters.has_music ? 1 : 0)
        + (filters.has_photo ? 1 : 0)
        + (filters.followed ? 1 : 0),
    );

    const isFiltered = computed(() => activeCount.value > 0);

    const reset = () => Object.assign(filters, { ...DEFAULT_FILTERS, genres: [], types: [] });

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

    // The last viewport written to the URL, so a filter change rewrites
    // the query without discarding where the listener is looking.
    let lastView = initialView;

    const syncUrl = (view) => {
        lastView = view ?? lastView;
        const next = new URLSearchParams(toQuery());

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

    return { filters, initialView, activeCount, isFiltered, reset, toQuery, syncUrl };
}
