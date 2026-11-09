<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet-providers';
import 'leaflet.markercluster';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FilterBar from '@/Components/Map/FilterBar.vue';
import ResultList from '@/Components/Map/ResultList.vue';
import ArtistCardPanel from '@/Components/Map/ArtistCardPanel.vue';
import { artistIcon, clusterIcon, markerLabel } from '@/Components/Map/markers';
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
const mobilePanel = ref(false);
const notice = ref(null);

// Distances are measured from the middle of what the listener is looking
// at, unless a radius is set -- then the circle has a fixed centre and
// panning must not drag it around.
const radiusOrigin = ref(null);

let map = null;
let markerLayer = null;
let clusterLayer = null;
let radiusCircle = null;
let homeMarker = null;
const markerById = new Map();

const reduceMotion = typeof window !== 'undefined'
    && (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false);
const unit = computed(() => meta.value?.unit ?? props.filterOptions.unit);

/* ------------------------------------------------------------------ */
/* Fetching                                                            */
/* ------------------------------------------------------------------ */

const buildParams = () => {
    const bounds = map.getBounds();
    const params = { ...toQuery() };

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

const drawMarkers = () => {
    markerLayer.clearLayers();
    markerById.clear();

    markers.value.forEach((artist) => {
        const marker = L.marker([artist.lat, artist.lng], {
            icon: artistIcon(artist, { active: artist.id === selectedId.value }),
            keyboard: true,
            title: markerLabel(artist, unit.value),
            alt: markerLabel(artist, unit.value),
            riseOnHover: true,
        });

        marker.on('click', () => selectArtist(artist, { pan: false }));
        marker.on('mouseover', () => (hoveredId.value = artist.id));
        marker.on('mouseout', () => (hoveredId.value = null));

        markerById.set(artist.id, marker);
        markerLayer.addLayer(marker);
    });
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

watch([markers, clusters], () => {
    drawMarkers();
    drawClusters();
});

watch(hoveredId, (id, previous) => {
    if (previous && previous !== selectedId.value) setPinState(previous, null);
    if (id && id !== selectedId.value) setPinState(id, 'hover');
});

/* ------------------------------------------------------------------ */
/* Selection                                                           */
/* ------------------------------------------------------------------ */

const selectArtist = (artist, { pan = true } = {}) => {
    if (selectedId.value) {
        setPinState(selectedId.value, null);
    }

    selectedId.value = artist.id;
    setPinState(artist.id, 'active');
    mobilePanel.value = true;

    const origin = map.getCenter();
    card.open(artist.id, {
        lat: origin.lat.toFixed(4),
        lng: origin.lng.toFixed(4),
        unit: props.filterOptions.unit,
    });

    if (pan) {
        map.panTo([artist.lat, artist.lng], { animate: !reduceMotion });
    }
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

/* ------------------------------------------------------------------ */
/* Moving the map                                                      */
/* ------------------------------------------------------------------ */

const goHome = () => {
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
            map.setView([coords.latitude, coords.longitude], 12, { animate: !reduceMotion });

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
    // provider with a variant option.
    L.tileLayer.provider('Jawg', {
        variant: 'jawg-dark',
        accessToken: import.meta.env.VITE_LEAFLET_JAWG_PROVIDER_KEY,
    }).addTo(map);

    // Individual pins group in the browser when they overlap; the bubble
    // is drawn exactly like a server-side cluster so the two are one idea.
    markerLayer = L.markerClusterGroup({
        showCoverageOnHover: false,
        maxClusterRadius: 48,
        spiderfyDistanceMultiplier: 1.6,
        animate: !reduceMotion,
        iconCreateFunction: (group) => clusterIcon(group.getChildCount()),
    });
    clusterLayer = L.layerGroup();
    map.addLayer(markerLayer);
    map.addLayer(clusterLayer);

    if (props.hasOwnLocation) {
        homeMarker = L.circleMarker([props.center.lat, props.center.lng], {
            radius: 6,
            color: '#fff',
            weight: 2,
            fillColor: '#d32f2f',
            fillOpacity: 0.9,
        }).bindTooltip('Your area').addTo(map);
    }

    map.on('moveend zoomend', () => load());
    load({ immediate: true });
});

onBeforeUnmount(() => {
    cancel();
    map?.remove();
    map = null;
});
</script>

<template>
    <Head title="Map" />

    <AuthenticatedLayout>
        <q-page class="map-page">
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
            />

            <div class="map-body">
                <!-- Side panel: list, or the selected artist -->
                <aside class="map-aside" :class="{ 'map-aside--open': mobilePanel }">
                    <ArtistCardPanel
                        v-if="selectedId"
                        :card="card.card.value"
                        :loading="card.loading.value"
                        :failed="card.failed.value"
                        :can-follow="canFollow"
                        @close="closeCard"
                        @followed="onFollowed"
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
                    />
                </aside>

                <div class="map-canvas">
                    <div id="map" class="h-full w-full" role="application" aria-label="Map of nearby artists"></div>

                    <!-- Transient messages: location, default area -->
                    <transition name="fade">
                        <div v-if="notice" class="map-notice" role="status">
                            {{ notice }}
                            <q-btn flat dense round size="sm" icon="close" @click="notice = null" />
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

                    <!-- Phone: a handle back to the list -->
                    <q-btn
                        class="map-sheet-toggle lg:hidden"
                        unelevated rounded no-caps color="primary"
                        :icon="mobilePanel ? 'expand_more' : 'expand_less'"
                        :label="mobilePanel ? 'Hide list' : `${meta?.returned ?? 0} nearby`"
                        @click="mobilePanel = !mobilePanel"
                    />
                </div>
            </div>
        </q-page>
    </AuthenticatedLayout>
</template>

<style scoped>
.map-page {
    display: flex;
    flex-direction: column;
    /* Fill the viewport below the Quasar header. */
    height: calc(100vh - 50px);
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
    transform: translateX(-50%);
    border-radius: 9999px;
    background: rgb(30 30 30 / 0.9);
    padding: 5px 14px;
    font-size: 0.75rem;
    color: var(--color-riff-muted);
    pointer-events: none;
}

.map-sheet-toggle {
    position: absolute;
    bottom: 16px;
    left: 16px;
    z-index: 1000;
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 150ms ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

/* Phone: the panel becomes a sheet over the map. */
@media (max-width: 1023px) {
    .map-aside {
        position: absolute;
        inset: auto 0 0 0;
        z-index: 1100;
        width: 100%;
        height: 65%;
        transform: translateY(100%);
        border-right: 0;
        border-top: 1px solid var(--color-riff-line);
        border-radius: 14px 14px 0 0;
        overflow: hidden;
        transition: transform 200ms ease;
    }

    .map-aside--open {
        transform: translateY(0);
    }

    .map-sheet-toggle {
        bottom: 16px;
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
