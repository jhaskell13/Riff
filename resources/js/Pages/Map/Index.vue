<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { onMounted, ref } from 'vue';
import "leaflet/dist/leaflet.css"
import * as L from 'leaflet';
import 'leaflet-providers';

const props = defineProps({
    currentLocation: {
        type: Object,
        default: { lat: 23.8041, lng: 90.4152 }
    },
    events: {
        type: Array,
        default: () => [],
    },
});

const initialMap = ref(null);

onMounted(()=> {
    initialMap.value = L.map('map', {
        minZoom: 0,
        maxZoom: 25,
        zoomControl: false,
    }).setView([props.currentLocation.lat, props.currentLocation.lng], 10);

    L.control.zoom({
        position: 'bottomright'
    }).addTo(initialMap.value);

    L.tileLayer.provider('Jawg.Dark', {
        accessToken: import.meta.env.VITE_LEAFLET_JAWG_PROVIDER_KEY
    }).addTo(initialMap.value);

    props.events.forEach(event => {
        event.locations.forEach(location => {
            L.marker([location.lat, location.lng]).addTo(initialMap.value);
        });
    });
});
</script>

<template>
    <AuthenticatedLayout class="relative">
        <div id="map" class="absolute top-[50px] bottom-0 w-full"></div>
    </AuthenticatedLayout>
</template>
