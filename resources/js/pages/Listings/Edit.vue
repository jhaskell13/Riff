<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ListingFields from '@/Components/ListingFields.vue';

const props = defineProps({
    listing: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    instruments: { type: Array, default: () => [] },
    genreOptions: { type: Array, default: () => [] },
    artistOptions: { type: Array, default: () => [] },
    home: { type: Object, default: () => ({}) },
});

const form = useForm({
    type: props.listing.type,
    artist_id: props.listing.artist_id,
    title: props.listing.title,
    body: props.listing.body,
    instruments: props.listing.instruments ?? [],
    genres: props.listing.genres ?? [],
    city: props.listing.city,
    state: props.listing.state,
    country: props.listing.country ?? '',
});
</script>

<template>
    <Head title="Edit listing" />
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-center gap-5">
                            <q-avatar size="48px" color="deep-purple-5" text-color="white">
                                <q-icon name="campaign" size="24px" />
                            </q-avatar>
                            <div class="text-h6 text-weight-bold text-grey-3">Edit listing</div>
                        </q-card-section>
                        <q-card-section>
                            <ListingFields
                                :form="form"
                                :types="types"
                                :instruments="instruments"
                                :genre-options="genreOptions"
                                :artist-options="artistOptions"
                                :home="home"
                                type-fixed
                            />
                        </q-card-section>
                        <q-card-actions align="right" class="q-px-md q-pb-md q-pt-none">
                            <Link :href="route('listings.show', listing.id)" class="text-caption text-grey-6 q-mr-md">Back</Link>
                            <q-btn unelevated rounded color="primary" label="Save" padding="xs lg" :loading="form.processing" @click="form.patch(route('listings.update', listing.id))" />
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
