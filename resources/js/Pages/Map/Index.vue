<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { onMounted, ref } from 'vue';
import * as L from 'leaflet';
import "leaflet/dist/leaflet.css"
import 'leaflet-providers';
import 'leaflet.markercluster';
import "leaflet.markercluster/dist/MarkerCluster.css";
import "leaflet.markercluster/dist/MarkerCluster.Default.css";


const props = defineProps({
    currentLocation: {
        type: Object,
        default: { lat: 23.8041, lng: 90.4152 }
    },
    localArtists: {
        type: Array,
        default: () => [],
    },
    events: {
        type: Array,
        default: () => [],
    },
});

const initialMap = ref(null);

onMounted(()=> {
    // Map initialization
    initialMap.value = L.map('map', {
        minZoom: 0,
        maxZoom: 25,
        zoomControl: false,
    }).setView([props.currentLocation.lat, props.currentLocation.lng], 12);

    // Control and Tile settings
    L.control.zoom({
        position: 'bottomright'
    }).addTo(initialMap.value);

    L.tileLayer.provider('Jawg.Dark', {
        accessToken: import.meta.env.VITE_LEAFLET_JAWG_PROVIDER_KEY
    }).addTo(initialMap.value);

    // Clusters and Markers
    let markers = L.markerClusterGroup({
        showCoverageOnHover: false,
        maxClusterRadius: 60,
        spiderfyDistanceMultiplier: 1.5, // smoother expansion
        animate: true,
        animateAddingMarkers: true,
        removeOutsideVisibleBounds: true,

        // iconCreateFunction(cluster) {
        //     const count = cluster.getChildCount();

        //     return L.divIcon({
        //         html: `
        //             <div class="cluster-inner">
        //                 <span>${count}</span>
        //             </div>
        //         `,
        //         className: "cluster-icon",
        //         iconSize: [40, 40]
        //     });
        // }
    });

    props.localArtists.forEach(artist => {
        markers.addLayer(L.marker([artist.location.lat, artist.location.lng]));
    });

    props.events.forEach(event => {
        event.locations.forEach(location => {
            markers.addLayer(L.marker([location.lat, location.lng]));
        });
    });

    initialMap.value.addLayer(markers);
});
</script>

<template>
    <AuthenticatedLayout class="relative">
        <div id="map" class="absolute top-[50px] bottom-0 w-full"></div>
    </AuthenticatedLayout>
</template>
