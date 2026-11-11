<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
// One Leaflet instance with its plugins attached; see that module.
import L from '@/Components/Map/leaflet';
// Leaflet + markercluster styles, layered below the app's theme.
import '../../../css/map-vendor.css';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FilterBar from '@/Components/Map/FilterBar.vue';
import ResultList from '@/Components/Map/ResultList.vue';
import ArtistCardPanel from '@/Components/Map/ArtistCardPanel.vue';
import WelcomeOverlay from '@/Components/Map/WelcomeOverlay.vue';
import { artistIcon, clusterIcon, markerLabel, youIcon } from '@/Components/Map/markers';
import { useArtistCard, useMapArtists } from '@/Composables/useMapData';
import { useMapFilters } from '@/Composables/useMapFilters';

const props = defineProps({
    center: { type: Object, required: true },
    hasOwnLocation: { type: Boolean, default: false },
    filterOptions: { type: Object, required: true },
    canFollow: { type: Boolean, default: false },
    maxMarkers: { type: Number, default: 500 },
});

const { filters, initialView, activeCount, isFiltered, reset, toQuery, syncUrl } = useMapFilters();
const { markers, clusters, meta, loading, failed, pristine, fetchArtists, retry, cancel } = useMapArtists();
const card = useArtistCard();

const selectedId = ref(null);
const hoveredId = ref(null);
const notice = ref(null);

// The side panel starts open on a wide screen and closed on a phone,
// where the map itself should be the first thing seen.
const panelOpen = ref(typeof window === 'undefined' ? true : window.innerWidth >= 1024);

// Distances are measured from the middle of what the listener is looking
// at, unless a radius is set -- then the circle has a fixed centre and
// panning must not drag it around.
const radiusOrigin = ref(null);

let map = null;
let markerLayer = null;
let clusterLayer = null;
let radiusCircle = null;
let youMarker = null;
let resizeTimer = null;
// A location the browser shared this session, held in memory only.
let locatedPoint = null;
const markerById = new Map();

const reduceMotion = typeof window !== 'undefined'
    && (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false);
const unit = computed(() => meta.value?.unit ?? props.filterOptions.unit);

/* ------------------------------------------------------------------ */
/* Welcome                                                             */
/* ------------------------------------------------------------------ */

const WELCOME_KEY = 'riff.onboarding.seen';
const welcomeOpen = ref(false);

// Storage can throw in private browsing; treat "cannot remember" as "seen"
// rather than greeting the same visitor every visit.
const welcomeSeen = () => {
    try {
        return window.localStorage.getItem(WELCOME_KEY) === '1';
    } catch {
        return true;
    }
};

const rememberWelcome = () => {
    try {
        window.localStorage.setItem(WELCOME_KEY, '1');
    } catch {
        // Private browsing; it just will not be remembered.
    }
};

const openWelcome = () => (welcomeOpen.value = true);

/* ------------------------------------------------------------------ */
/* Fetching                                                            */
/* ------------------------------------------------------------------ */

const searchTerm = () => (filters.q.trim().length >= 2 ? filters.q.trim() : null);

const buildParams = () => {
    const bounds = map.getBounds();
    const params = { ...toQuery() };

    // A name search looks everywhere. Confining it to the viewport would
    // mean a listener searching for an artist they have heard of finds
    // nothing unless they are already looking at the right town; the map
    // is fitted to the results instead.
    if (searchTerm() && !filters.radius) {
        const centre = map.getCenter();
        params.lat = centre.lat;
        params.lng = centre.lng;

        return params;
    }

    if (filters.radius) {
        // Keep the circle where it was placed; default it to the current
        // centre the first time a radius is chosen.
        radiusOrigin.value ??= map.getCenter();
        params.lat = radiusOrigin.value.lat;
        params.lng = radiusOrigin.value.lng;
        params.unit = props.filterOptions.unit;
    } else {
        radiusOrigin.value = null;
        params.south = bounds.getSouth();
        params.north = bounds.getNorth();
        params.west = bounds.getWest();
        params.east = bounds.getEast();

        const centre = map.getCenter();
        params.lat = centre.lat;
        params.lng = centre.lng;
    }

    return params;
};

const load = (options) => {
    fetchArtists(buildParams(), options);

    const centre = map.getCenter();
    syncUrl({ lat: centre.lat, lng: centre.lng, zoom: map.getZoom() });
};

/* ------------------------------------------------------------------ */
/* Drawing                                                             */
/* ------------------------------------------------------------------ */

const setPinState = (id, state) => {
    const marker = markerById.get(id);

    if (!marker) {
        return;
    }

    const element = marker.getElement()?.querySelector('.riff-pin');
    element?.classList.toggle('riff-pin--active', state === 'active');
    element?.classList.toggle('riff-pin--hover', state === 'hover');
};

// Latest data per artist, read by marker handlers so a kept marker never
// acts on a stale copy.
const artistById = new Map();

const buildMarker = (artist) => {
    const marker = L.marker([artist.lat, artist.lng], {
        icon: artistIcon(artist, { active: artist.id === selectedId.value }),
        keyboard: true,
        title: markerLabel(artist, unit.value),
        alt: markerLabel(artist, unit.value),
        riseOnHover: true,
    });

    marker.on('click', () => selectArtist(artistById.get(artist.id), { pan: false }));
    marker.on('mouseover', () => (hoveredId.value = artist.id));
    marker.on('mouseout', () => (hoveredId.value = null));

    return marker;
};

/**
 * Bring the pins in line with the latest results by difference, not by
 * rebuilding. markercluster collapses an opened (spiderfied) cluster on
 * any add or remove, and nearly every pan re-fetches -- usually returning
 * the same artists -- so a full redraw snapped shut every cluster the
 * listener had just opened.
 */
const drawMarkers = () => {
    const next = new Map(markers.value.map((artist) => [artist.id, artist]));
    next.forEach((artist, id) => artistById.set(id, artist));

    const gone = [...markerById.keys()].filter((id) => !next.has(id));
    const added = [...next.keys()].filter((id) => !markerById.has(id));

    if (gone.length) {
        markerLayer.removeLayers(gone.map((id) => markerById.get(id)));
        gone.forEach((id) => {
            markerById.delete(id);
            artistById.delete(id);
        });
    }

    if (added.length) {
        const fresh = added.map((id) => {
            const marker = buildMarker(next.get(id));
            markerById.set(id, marker);

            return marker;
        });
        markerLayer.addLayers(fresh);
    }

    // Distances follow the origin, so refresh labels on the pins we kept.
    markerById.forEach((marker, id) => {
        const label = markerLabel(next.get(id), unit.value);
        marker.options.title = label;
        marker.getElement()?.setAttribute('title', label);
    });
};

/**
 * Artists in one town share its exact centre, so no zoom level separates
 * them: zooming in on such a cluster only ever showed the same bubble at
 * street level. Those, and any cluster already at the closest useful zoom,
 * fan out in place instead.
 */
const opensInPlace = (cluster) => {
    const bounds = cluster.getBounds();

    return bounds.getNorthEast().equals(bounds.getSouthWest())
        || map.getBoundsZoom(bounds) <= map.getZoom();
};

const drawClusters = () => {
    clusterLayer.clearLayers();

    (clusters.value ?? []).forEach((cluster) => {
        const marker = L.marker([cluster.lat, cluster.lng], {
            icon: clusterIcon(cluster.count),
            keyboard: true,
            title: `${cluster.count} artists — zoom in`,
            alt: `${cluster.count} artists in this area`,
        });

        marker.on('click', () => {
            const { south, west, north, east } = cluster.bounds;

            // A cluster whose artists share one point has no extent to fit;
            // step in instead of zooming to maximum.
            if (south === north && west === east) {
                map.setView([south, west], Math.min(map.getZoom() + 3, map.getMaxZoom()));

                return;
            }

            map.fitBounds(L.latLngBounds([south, west], [north, east]), { padding: [48, 48] });
        });

        clusterLayer.addLayer(marker);
    });
};

const drawRadius = () => {
    radiusCircle?.remove();
    radiusCircle = null;

    if (!filters.radius || !radiusOrigin.value) {
        return;
    }

    const metresPerUnit = props.filterOptions.unit === 'mi' ? 1609.344 : 1000;

    radiusCircle = L.circle(radiusOrigin.value, {
        radius: filters.radius * metresPerUnit,
        color: '#d32f2f',
        weight: 1,
        fillColor: '#d32f2f',
        fillOpacity: 0.06,
        interactive: false,
    }).addTo(map);
};

let fittedTerm = null;

watch([markers, clusters], () => {
    drawMarkers();
    drawClusters();

    // Move to the results of a new search, once. Re-fitting on every
    // response would fight the listener for control of the viewport.
    const term = searchTerm();

    if (term && term !== fittedTerm && meta.value?.mode === 'search' && markers.value.length) {
        fittedTerm = term;
        const extent = L.latLngBounds(markers.value.map((artist) => [artist.lat, artist.lng]));
        map.fitBounds(extent, { padding: [60, 60], maxZoom: 13, animate: !reduceMotion });
    }

    if (!term) {
        fittedTerm = null;
    }
});

watch(hoveredId, (id, previous) => {
    if (previous && previous !== selectedId.value) setPinState(previous, null);
    if (id && id !== selectedId.value) setPinState(id, 'hover');
});

/**
 * Place (or move) the viewer's own marker: their profile area, or a
 * location the browser shared this session.
 */
const showYouAreHere = (lat, lng, label) => {
    if (youMarker) {
        youMarker.setLatLng([lat, lng]);

        return;
    }

    // It sits on the town centre, exactly where that town's artists and
    // their cluster are. Drawn beneath them (negative offset) as a halo
    // wider than a cluster bubble, it frames them instead of hiding them;
    // non-interactive, so Leaflet gives it pointer-events: none and every
    // click falls through to the artists.
    youMarker = L.marker([lat, lng], {
        icon: youIcon(),
        zIndexOffset: -10000,
        interactive: false,
        keyboard: false,
        alt: label,
    }).addTo(map);
};

/* ------------------------------------------------------------------ */
/* Selection                                                           */
/* ------------------------------------------------------------------ */

const selectArtist = (artist, { pan = true } = {}) => {
    if (selectedId.value) {
        setPinState(selectedId.value, null);
    }

    selectedId.value = artist.id;
    setPinState(artist.id, 'active');
    panelOpen.value = true;

    const origin = map.getCenter();
    card.open(artist.id, {
        lat: origin.lat.toFixed(4),
        lng: origin.lng.toFixed(4),
        unit: props.filterOptions.unit,
    });

    if (!pan) {
        return;
    }

    const marker = markerById.get(artist.id);

    if (!marker || !markerLayer.hasLayer(marker)) {
        map.panTo([artist.lat, artist.lng], { animate: !reduceMotion });

        return;
    }

    // The pin may be inside a cluster bubble, where panning to it would
    // show the listener nothing.
    const parent = markerLayer.getVisibleParent(marker);

    if (parent && parent !== marker && opensInPlace(parent)) {
        markerLayer.once('spiderfied', () => setPinState(artist.id, 'active'));
        map.panTo(parent.getLatLng(), { animate: !reduceMotion });
        parent.spiderfy();

        return;
    }

    markerLayer.zoomToShowLayer(marker, () => setPinState(artist.id, 'active'));
};

const closeCard = () => {
    if (selectedId.value) {
        setPinState(selectedId.value, null);
    }

    selectedId.value = null;
    card.close();
};

// Following changes the card's own state, so its cached copy is stale.
const onFollowed = (id) => card.forget(id);

// A blocked artist leaves this viewer's map: close the card and refetch.
const onBlocked = (id) => {
    card.forget(id);
    closeCard();
    notice.value = 'Blocked. They won\'t appear on your map or in your feed.';
    load({ immediate: true });
};

// Unblocking puts them back; the card stays open, refreshed.
const onUnblocked = (id) => {
    card.forget(id);
    card.open(id, {
        lat: map.getCenter().lat.toFixed(4),
        lng: map.getCenter().lng.toFixed(4),
        unit: props.filterOptions.unit,
    });
    load({ immediate: true });
};

/* ------------------------------------------------------------------ */
/* Moving the map                                                      */
/* ------------------------------------------------------------------ */

const goHome = () => {
    if (locatedPoint) {
        map.setView(locatedPoint, 12, { animate: !reduceMotion });

        return;
    }

    map.setView([props.center.lat, props.center.lng], 11, { animate: !reduceMotion });

    if (!props.hasOwnLocation) {
        notice.value = props.canFollow
            ? 'Add your city in your profile and the map will start here.'
            : 'Showing the default area — sign in to centre the map on your own.';
    }
};

const locate = () => {
    if (!navigator.geolocation) {
        notice.value = 'This browser cannot share a location.';

        return;
    }

    notice.value = 'Finding your location…';

    navigator.geolocation.getCurrentPosition(
        ({ coords }) => {
            notice.value = null;
            // Used for this view only: never stored, and never sent
            // anywhere except as the origin of a map query.
            locatedPoint = L.latLng(coords.latitude, coords.longitude);
            map.setView(locatedPoint, 12, { animate: !reduceMotion });
            showYouAreHere(coords.latitude, coords.longitude, 'Your location');

            if (filters.radius) {
                radiusOrigin.value = L.latLng(coords.latitude, coords.longitude);
                drawRadius();
                load({ immediate: true });
            }
        },
        () => (notice.value = 'Couldn\'t get your location. Check your browser permissions.'),
        { timeout: 10000, maximumAge: 300000 },
    );
};

const jumpToPlace = (place) => {
    map.setView([place.lat, place.lng], 12, { animate: !reduceMotion });
    radiusOrigin.value = filters.radius ? L.latLng(place.lat, place.lng) : null;
};

const clearFilters = () => {
    reset();
    radiusOrigin.value = null;
};

// Declared first so the circle's centre is settled before the general
// watcher below builds a request from it.
watch(() => filters.radius, (radius) => {
    if (map) {
        radiusOrigin.value = radius ? map.getCenter() : null;
    }
});

watch(() => ({ ...filters, genres: [...filters.genres], types: [...filters.types] }), () => {
    if (!map) {
        return;
    }

    drawRadius();
    load();
}, { deep: true });

// Collapsing the panel resizes the map's container; Leaflet only
// recalculates on demand, and skipping this leaves grey tiles.
watch(panelOpen, async () => {
    await nextTick();
    map?.invalidateSize({ animate: false });
    // Again once the width transition has finished, or the map keeps the
    // size it had partway through the animation.
    resizeTimer = setTimeout(() => map?.invalidateSize({ animate: false }), 240);
});

/* ------------------------------------------------------------------ */
/* Lifecycle                                                           */
/* ------------------------------------------------------------------ */

onMounted(() => {
    const start = initialView ?? { lat: props.center.lat, lng: props.center.lng, zoom: 11 };

    map = L.map('map', {
        minZoom: 3,
        maxZoom: 18,
        zoomControl: false,
        zoomAnimation: !reduceMotion,
        fadeAnimation: !reduceMotion,
        markerZoomAnimation: !reduceMotion,
    }).setView([start.lat, start.lng], start.zoom);

    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.control.scale({ position: 'bottomleft', imperial: props.filterOptions.unit === 'mi', metric: props.filterOptions.unit === 'km' }).addTo(map);

    // leaflet-providers v4 folded the named Jawg styles into a single
    // provider with a variant option. Without a key the provider throws
    // on every tile -- inside onMounted, which aborted the rest of the
    // map setup -- so a missing key now leaves the map tile-less but
    // fully working, and says so.
    const tileKey = import.meta.env.VITE_LEAFLET_JAWG_PROVIDER_KEY;

    if (tileKey) {
        L.tileLayer.provider('Jawg', {
            variant: 'jawg-dark',
            accessToken: tileKey,
            // Hold more off-screen tiles so a pan reveals map, not
            // background, while new tiles load.
            keepBuffer: 4,
        }).addTo(map);
    } else {
        console.warn('Riff map: VITE_LEAFLET_JAWG_PROVIDER_KEY is not set; map tiles are disabled.');
        notice.value = 'Map tiles are unavailable (no tile key configured). Artists still load.';
    }

    // Individual pins group in the browser when they overlap; the bubble
    // is drawn exactly like a server-side cluster so the two are one idea.
    markerLayer = L.markerClusterGroup({
        showCoverageOnHover: false,
        maxClusterRadius: 48,
        spiderfyDistanceMultiplier: 1.6,
        animate: !reduceMotion,
        // Clicks are handled below so same-town clusters open in place.
        zoomToBoundsOnClick: false,
        spiderLegPolylineOptions: { weight: 1.5, color: '#9e9e9e', opacity: 0.7 },
        iconCreateFunction: (group) => clusterIcon(group.getChildCount()),
    });
    markerLayer.on('clusterclick', ({ layer: cluster }) => {
        if (opensInPlace(cluster)) {
            cluster.spiderfy();

            return;
        }

        cluster.zoomToBounds({ padding: [48, 48] });
    });
    clusterLayer = L.layerGroup();
    map.addLayer(markerLayer);
    map.addLayer(clusterLayer);

    if (props.hasOwnLocation) {
        showYouAreHere(props.center.lat, props.center.lng, 'Your area');
    }

    map.on('moveend zoomend', () => load());

    // A radius carried in from the URL needs its circle drawn now;
    // buildParams settles its origin on this first call.
    load({ immediate: true });
    drawRadius();

    // First-time guests only, and never over a shared link or filtered
    // view; recorded on open so it shows exactly once.
    if (!props.canFollow && !initialView && !isFiltered.value && !welcomeSeen()) {
        welcomeOpen.value = true;
        rememberWelcome();
    }
});

onBeforeUnmount(() => {
    clearTimeout(resizeTimer);
    cancel();
    map?.remove();
    map = null;
});
</script>

<template>
    <Head title="Map" />

    <AuthenticatedLayout>
        <q-page class="map-page" :style-fn="(offset) => ({ height: `calc(100dvh - ${offset}px)` })">
            <FilterBar
                :filters="filters"
                :options="filterOptions"
                :active-count="activeCount"
                :can-follow="canFollow"
                :busy="loading && !pristine"
                @reset="clearFilters"
                @jump="jumpToPlace"
                @locate="locate"
                @home="goHome"
                @help="openWelcome"
            />

            <WelcomeOverlay
                v-model="welcomeOpen"
                :signed-in="canFollow"
                :reduce-motion="reduceMotion"
            />

            <div class="map-body">
                <!-- Side panel: list, or the selected artist -->
                <aside class="map-aside" :class="{ 'map-aside--closed': !panelOpen }" :inert="panelOpen ? undefined : true">
                    <ArtistCardPanel
                        v-if="selectedId"
                        :card="card.card.value"
                        :loading="card.loading.value"
                        :failed="card.failed.value"
                        :can-follow="canFollow"
                        @close="closeCard"
                        @followed="onFollowed"
                        @blocked="onBlocked"
                        @unblocked="onUnblocked"
                        @collapse="panelOpen = false"
                    />
                    <ResultList
                        v-else
                        :artists="markers"
                        :meta="meta"
                        :loading="loading"
                        :failed="failed"
                        :pristine="pristine"
                        :selected-id="selectedId"
                        :is-filtered="isFiltered"
                        :can-follow="canFollow"
                        @select="selectArtist"
                        @hover="(id) => (hoveredId = id)"
                        @retry="retry"
                        @clear-filters="clearFilters"
                        @collapse="panelOpen = false"
                    />
                </aside>

                <div class="map-canvas">
                    <div id="map" class="h-full w-full" role="application" aria-label="Map of nearby artists"></div>

                    <!-- Transient messages: location, default area -->
                    <transition name="fade">
                        <div v-if="notice" class="map-notice" role="status">
                            {{ notice }}
                            <q-btn flat dense round size="sm" icon="close" aria-label="Dismiss" @click="notice = null" />
                        </div>
                    </transition>

                    <!-- Failure is reported over the map too: the list may
                         be off screen on a phone. -->
                    <transition name="fade">
                        <div v-if="failed" class="map-error" role="alert">
                            Couldn't load this area.
                            <q-btn flat dense no-caps color="white" label="Retry" class="q-ml-sm" @click="retry" />
                        </div>
                    </transition>

                    <div v-if="meta?.mode === 'clusters'" class="map-hint">
                        <q-icon name="zoom_in" size="16px" class="q-mr-xs" />
                        Zoom in to see individual artists
                    </div>

                    <!-- Re-open the panel: a tab on the left edge of a wide
                         screen, a pill above the map on a phone. -->
                    <button
                        v-show="!panelOpen"
                        type="button"
                        class="map-reopen"
                        :aria-label="`Show the artist list (${meta?.returned ?? 0} here)`"
                        @click="panelOpen = true"
                    >
                        <q-icon class="map-reopen__wide" name="chevron_right" size="20px" />
                        <q-icon class="map-reopen__narrow" name="expand_less" size="18px" />
                        <span class="map-reopen__count">{{ meta?.returned ?? 0 }}</span>
                        <span class="map-reopen__word">nearby</span>
                    </button>
                </div>
            </div>
        </q-page>
    </AuthenticatedLayout>
</template>

<style scoped>
.map-page {
    display: flex;
    flex-direction: column;
    /* Height comes from the style-fn above, which knows the header and
       footer offsets; this only stops the page scrolling as a whole. */
    overflow: hidden;
    background: var(--color-riff-bg);
}

.map-body {
    position: relative;
    display: flex;
    min-height: 0;
    flex: 1;
}

.map-canvas {
    position: relative;
    flex: 1;
    min-width: 0;
}

.map-aside {
    width: 360px;
    flex-shrink: 0;
    border-right: 1px solid var(--color-riff-line);
    min-height: 0;
    transition: width 200ms ease;
}

/* Wide screens: the panel collapses to nothing, leaving the map full
   width and a tab on its edge to bring it back. */
.map-aside--closed {
    width: 0;
    border-right: 0;
    overflow: hidden;
}

/* Sits where the panel's own header row was, so closing and reopening
   happens at the same spot. */
.map-reopen {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: 6px;
    height: 41px;
    padding: 0 12px 0 6px;
    border: 1px solid var(--color-riff-line);
    border-top: 0;
    border-left: 0;
    border-radius: 0 0 10px 0;
    background: var(--color-riff-surface);
    color: var(--color-riff-text);
    font-size: 0.75rem;
    white-space: nowrap;
    cursor: pointer;
    box-shadow: 2px 2px 8px rgb(0 0 0 / 0.35);
}

.map-reopen:hover {
    background: var(--color-riff-surface-2);
}

.map-reopen:focus-visible {
    outline: 2px solid #fff;
    outline-offset: -2px;
}

.map-reopen__narrow {
    display: none;
}

.map-reopen__count {
    font-weight: 600;
}

.map-reopen__word {
    color: var(--color-riff-muted);
}

.map-notice,
.map-error {
    position: absolute;
    top: 12px;
    left: 50%;
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: 4px;
    max-width: calc(100% - 24px);
    transform: translateX(-50%);
    border-radius: 9999px;
    padding: 6px 8px 6px 16px;
    font-size: 0.8rem;
    box-shadow: 0 4px 14px rgb(0 0 0 / 0.4);
}

.map-notice {
    background: var(--color-riff-surface-2);
    color: var(--color-riff-text);
}

.map-error {
    background: #7f1d1d;
    color: #fee2e2;
}

.map-hint {
    position: absolute;
    bottom: 16px;
    left: 50%;
    z-index: 1000;
    max-width: calc(100% - 24px);
    transform: translateX(-50%);
    border-radius: 9999px;
    background: rgb(30 30 30 / 0.9);
    padding: 5px 14px;
    font-size: 0.75rem;
    color: var(--color-riff-muted);
    pointer-events: none;
    text-align: center;
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 150ms ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

/* Phones: the panel is a sheet over the map, and the tab becomes a pill
   sitting above the sheet's resting position. */
@media (max-width: 1023px) {
    .map-aside {
        position: absolute;
        inset: auto 0 0 0;
        z-index: 1100;
        width: 100%;
        height: 70%;
        border-right: 0;
        border-top: 1px solid var(--color-riff-line);
        border-radius: 14px 14px 0 0;
        overflow: hidden;
        box-shadow: 0 -6px 20px rgb(0 0 0 / 0.45);
        transition: transform 200ms ease, box-shadow 200ms ease;
    }

    /* Off screen it must not cast a shadow back up over the map. */
    .map-aside--closed {
        width: 100%;
        transform: translateY(100%);
        box-shadow: none;
    }

    /* Centred pill, clear of the attribution row. Leaflet keeps its own
       corners: scale bottom-left, zoom and attribution bottom-right, the
       attribution flush to the edge. */
    .map-reopen {
        top: auto;
        bottom: 30px;
        left: 50%;
        height: auto;
        transform: translateX(-50%);
        padding: 8px 16px 8px 12px;
        border: 0;
        border-radius: 9999px;
        background: var(--color-riff-primary);
        color: #fff;
        box-shadow: 0 2px 10px rgb(0 0 0 / 0.45);
    }

    .map-reopen:hover {
        background: var(--color-riff-primary);
    }

    .map-reopen__wide {
        display: none;
    }

    .map-reopen__narrow {
        display: inline-flex;
    }

    .map-reopen__word {
        color: rgb(255 255 255 / 0.85);
    }

    /* The zoom hint would sit under the pill; lift it above. */
    .map-hint {
        bottom: 80px;
    }

    /* At phone width the full attribution wraps into the scale bar;
       smaller type keeps it on one line in its corner. */
    .map-canvas :deep(.leaflet-control-attribution) {
        font-size: 10px;
        line-height: 1.6;
    }
}

@media (prefers-reduced-motion: reduce) {
    .map-aside,
    .fade-enter-active,
    .fade-leave-active {
        transition: none;
    }
}
</style>
