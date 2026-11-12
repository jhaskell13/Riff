<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ListingFields from '@/Components/ListingFields.vue';

const props = defineProps({
    types: { type: Array, default: () => [] },
    instruments: { type: Array, default: () => [] },
    genreOptions: { type: Array, default: () => [] },
    artistOptions: { type: Array, default: () => [] },
    home: { type: Object, default: () => ({}) },
    preset: { type: Object, default: () => ({}) },
});

// Start as an artist post when the poster has an artist (from the zone,
// that artist); otherwise as a person looking for a band.
const presetArtist = props.artistOptions.find((a) => a.value === props.preset.artist_id) ?? props.artistOptions[0] ?? null;
const presetType = props.types.some((t) => t.value === props.preset.type)
    ? props.preset.type
    : (presetArtist ? 'members_wanted' : 'band_wanted');
const asPerson = props.types.find((t) => t.value === presetType)?.asArtist === 'never';
const town = asPerson ? props.home : (presetArtist ?? props.home);

const form = useForm({
    type: presetType,
    artist_id: asPerson ? null : presetArtist?.value ?? null,
    title: '',
    body: '',
    instruments: [],
    genres: [],
    city: town?.city ?? '',
    state: town?.state ?? '',
    country: town?.country ?? '',
});
</script>

<template>
    <Head title="Post a listing" />
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-center gap-5">
                            <q-avatar size="48px" color="deep-purple-5" text-color="white">
                                <q-icon name="campaign" size="24px" />
                            </q-avatar>
                            <div class="leading-tight">
                                <div class="text-h6 text-weight-bold text-grey-3">Post a listing</div>
                                <div class="text-caption text-grey-6">Open for 30 days. Replies reach you by email; your address stays private.</div>
                            </div>
                        </q-card-section>
                        <q-card-section>
                            <ListingFields
                                :form="form"
                                :types="types"
                                :instruments="instruments"
                                :genre-options="genreOptions"
                                :artist-options="artistOptions"
                                :home="home"
                            />
                        </q-card-section>
                        <q-card-actions align="right" class="q-px-md q-pb-md q-pt-none">
                            <q-btn unelevated rounded color="primary" label="Post" padding="xs lg" :loading="form.processing" @click="form.post(route('listings.store'))" />
                        </q-card-actions>
                    </q-card>
                </div>
            </div>
        </q-page>
    </AuthenticatedLayout>
</template>

<style scoped>
.page-bg {
    background: #121212;
    min-height: 100vh;
}

.post-card {
    background: #1e1e1e;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
</style>
