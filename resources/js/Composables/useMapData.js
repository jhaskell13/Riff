import { ref, shallowRef } from 'vue';

/**
 * Talks to one map endpoint (artists, shows, or venues — each kind has its
 * own, with its own query shape and cache).

 *
 * Panning fires constantly, so every fetch is debounced and the previous
 * one is aborted: only the newest viewport is ever in flight, and a slow
 * earlier response can never overwrite a newer one. An aborted request is
 * not an error and must not flip the UI into its error state.
 */
export function useMapFeed(routeName) {
    const markers = shallowRef([]);
    const clusters = shallowRef(null);
    const meta = ref(null);
    const loading = ref(false);
    const failed = ref(false);
    // True until the first successful response, so the empty state is not
    // shown before anything has loaded.
    const pristine = ref(true);

    let controller = null;
    let debounceTimer = null;
    let lastParams = null;

    const run = async (params) => {
        controller?.abort();
        controller = new AbortController();
        lastParams = params;
        loading.value = true;

        try {
            const response = await fetch(`${route(routeName)}?${new URLSearchParams(params)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(`Map request failed (${response.status})`);
            }

            const payload = await response.json();

            markers.value = payload.data;
            clusters.value = payload.clusters;
            meta.value = payload.meta;
            failed.value = false;
            pristine.value = false;
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            failed.value = true;
        } finally {
            // A newer request may already be running; it owns the flag.
            if (controller?.signal.aborted === false) {
                loading.value = false;
            }
        }
    };

    const fetchArtists = (params, { immediate = false } = {}) => {
        clearTimeout(debounceTimer);

        if (immediate) {
            return run(params);
        }

        loading.value = true;
        debounceTimer = setTimeout(() => run(params), 300);
    };

    const retry = () => lastParams && run(lastParams);

    const cancel = () => {
        clearTimeout(debounceTimer);
        controller?.abort();
    };

    /**
     * Turn a kind off: drop its pins and settle its state, so a hidden
     * kind never reads as loading or failed.
     */
    const clear = () => {
        cancel();
        markers.value = [];
        clusters.value = null;
        meta.value = null;
        loading.value = false;
        failed.value = false;
    };

    return { markers, clusters, meta, loading, failed, pristine, fetch: fetchArtists, fetchArtists, retry, cancel, clear };
}

export const useMapArtists = () => useMapFeed('map.artists');

/**
 * The expanded card for one artist. Cards are cached for the session: a
 * listener comparing two artists should not re-fetch on every click.
 */
export function useArtistCard() {
    const card = ref(null);
    const loading = ref(false);
    const failed = ref(false);
    const cache = new Map();

    let controller = null;

    const open = async (id, origin) => {
        controller?.abort();

        const key = `${id}:${origin.lat}:${origin.lng}:${origin.unit}`;

        if (cache.has(key)) {
            card.value = cache.get(key);
            failed.value = false;

            return;
        }

        controller = new AbortController();
        card.value = null;
        loading.value = true;
        failed.value = false;

        try {
            const params = new URLSearchParams(origin);
            const response = await fetch(`${route('map.artists.card', id)}?${params}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(`Card request failed (${response.status})`);
            }

            const { data } = await response.json();
            cache.set(key, data);
            card.value = data;
        } catch (error) {
            if (error.name !== 'AbortError') {
                failed.value = true;
            }
        } finally {
            loading.value = false;
        }
    };

    const close = () => {
        controller?.abort();
        card.value = null;
        loading.value = false;
        failed.value = false;
    };

    /** Drop a cached card after its follow state changes. */
    const forget = (id) => {
        for (const key of cache.keys()) {
            if (key.startsWith(`${id}:`)) {
                cache.delete(key);
            }
        }
    };

    return { card, loading, failed, open, close, forget };
}

/**
 * Town suggestions for the "jump to" box. These come from artists already
 * on the map, so the list only ever offers places worth travelling to.
 */
export function usePlaceSearch() {
    const places = ref([]);
    let controller = null;
    let debounceTimer = null;

    const search = (term) => {
        clearTimeout(debounceTimer);
        controller?.abort();

        if (!term || term.trim().length < 2) {
            places.value = [];

            return;
        }

        debounceTimer = setTimeout(async () => {
            controller = new AbortController();

            try {
                const response = await fetch(`${route('map.places')}?q=${encodeURIComponent(term.trim())}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });

                places.value = response.ok ? (await response.json()).data : [];
            } catch {
                places.value = [];
            }
        }, 250);
    };

    const clear = () => {
        clearTimeout(debounceTimer);
        controller?.abort();
        places.value = [];
    };

    return { places, search, clear };
}
