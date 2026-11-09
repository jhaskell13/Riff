<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { onMounted, onBeforeUnmount, ref } from 'vue';
import * as L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet-providers';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

// Override Leaflet's default icon paths
delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
});

const props = defineProps({
    center: {
        type: Object,
        required: true,
    },
    hasOwnLocation: {
        type: Boolean,
        default: false,
    },
});

const loading = ref(false);
const loadFailed = ref(false);

let map = null;
let clusterGroup = null;
let abortController = null;
let debounceTimer = null;

// Popup content is built via DOM nodes, never HTML strings:
// artist names are user input and must not be injected as markup.
const popupFor = (artist) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'artist-popup';

    const name = document.createElement('a');
    name.href = artist.url;
    name.textContent = artist.name;
    name.className = 'artist-popup-name';
    wrapper.appendChild(name);

    const detail = document.createElement('div');
    detail.textContent = [(artist.genres ?? []).join(', '), artist.location].filter(Boolean).join(' • ');
    detail.className = 'artist-popup-detail';
    wrapper.appendChild(detail);

    return wrapper;
};

const fetchVisibleArtists = async () => {
    abortController?.abort();
    abortController = new AbortController();

    const bounds = map.getBounds();
    const params = new URLSearchParams({
        south: bounds.getSouth(),
        north: bounds.getNorth(),
        west: bounds.getWest(),
        east: bounds.getEast(),
    });

    loading.value = true;

    try {
        const response = await fetch(`${route('map.artists')}?${params}`, {
            headers: { Accept: 'application/json' },
            signal: abortController.signal,
        });

        if (!response.ok) {
            throw new Error(`Map artists request failed (${response.status})`);
        }

        const { data } = await response.json();

        clusterGroup.clearLayers();
        data.forEach((artist) => {
            clusterGroup.addLayer(
                L.marker([artist.lat, artist.lng]).bindPopup(popupFor(artist)),
            );
        });

        loadFailed.value = false;
    } catch (error) {
        if (error.name !== 'AbortError') {
            loadFailed.value = true;
        }
    } finally {
        loading.value = false;
    }
};

const scheduleFetch = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(fetchVisibleArtists, 300);
};

onMounted(() => {
    map = L.map('map', {
        minZoom: 3,
        maxZoom: 18,
        zoomControl: false,
    }).setView([props.center.lat, props.center.lng], 11);

    L.control.zoom({ position: 'bottomright' }).addTo(map);

    // leaflet-providers v4 folded the named Jawg styles into a single
    // provider with a variant option.
    L.tileLayer.provider('Jawg', {
        variant: 'jawg-dark',
        accessToken: import.meta.env.VITE_LEAFLET_JAWG_PROVIDER_KEY,
    }).addTo(map);

    clusterGroup = L.markerClusterGroup({
        showCoverageOnHover: false,
        maxClusterRadius: 60,
        spiderfyDistanceMultiplier: 1.5,
        animate: true,
        removeOutsideVisibleBounds: true,
    });
    map.addLayer(clusterGroup);

    if (props.hasOwnLocation) {
        L.circleMarker([props.center.lat, props.center.lng], {
            radius: 7,
            color: '#667eea',
            fillColor: '#667eea',
            fillOpacity: 0.6,
        }).bindTooltip('Your area').addTo(map);
    }

    map.on('moveend zoomend', scheduleFetch);
    fetchVisibleArtists();
});

onBeforeUnmount(() => {
    clearTimeout(debounceTimer);
    abortController?.abort();
    map?.remove();
});
</script>

<template>
    <AuthenticatedLayout class="relative">
        <div id="map" class="absolute top-[50px] bottom-0 w-full"></div>
        <div
            v-if="loadFailed"
            class="absolute top-[60px] left-1/2 -translate-x-1/2 z-1000 rounded-sm bg-red-900/90 px-4 py-2 text-sm text-red-100"
        >
            Couldn't load artists for this area. Move the map to retry.
        </div>
    </AuthenticatedLayout>
</template>

<style>
.artist-popup-name {
    display: block;
    font-weight: 600;
    font-size: 0.95rem;
}

.artist-popup-detail {
    margin-top: 2px;
    font-size: 0.8rem;
    color: #555;
}
</style>
