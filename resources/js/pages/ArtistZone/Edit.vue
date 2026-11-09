<script setup>
import { useForm, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ArtistProfileFields from '@/Components/ArtistProfileFields.vue';

const props = defineProps({
    artist: {
        type: Object,
        required: true,
    },
    genreOptions: { type: Array, default: () => [] },
    artistTypes: { type: Array, default: () => [] },
    maxGenres: { type: Number, default: 3 },
});

const form = useForm({
    name: props.artist.name,
    artist_type: props.artist.artist_type,
    genres: props.artist.genres.map((genre) => genre.id),
    biography: props.artist.biography ?? '',
    hometown: props.artist.hometown,
    stateOrProvince: props.artist.stateOrProvince,
    country: props.artist.country,
    show_on_map: props.artist.show_on_map,
    music_url: props.artist.music_url ?? '',
    website: props.artist.website ?? '',
    bandcamp_url: props.artist.bandcamp_url ?? '',
    spotify_url: props.artist.spotify_url ?? '',
    soundcloud_url: props.artist.soundcloud_url ?? '',
    youtube_url: props.artist.youtube_url ?? '',
    instagram_url: props.artist.instagram_url ?? '',
    email: props.artist.email ?? '',
});

const submit = () => {
    form.patch(route('artists.update', props.artist.id));
};

const archive = () => {
    if (confirm(`Archive ${props.artist.name}? The identity is hidden from Riff but can be restored later.`)) {
        router.delete(route('artists.destroy', props.artist.id));
    }
};
</script>

<template>
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-pb-md q-mb-md">
                        <q-card-section class="row flex-nowrap! items-center gap-5">
                            <q-avatar class="self-start q-mt-sm" size="48px" color="primary" text-color="white">
                                <q-icon name="edit" size="24px" />
                            </q-avatar>
                            <div class="leading-tight">
                                <div class="text-h6 text-weight-bold text-grey-3">Edit {{ artist.name }}</div>
                                <p class="text-grey-7 m-0">
                                    Changes are visible to everyone who can see this artist profile.
                                </p>
                            </div>
                        </q-card-section>

                        <q-separator color="grey-9" />

                        <q-card-section>
                            <ArtistProfileFields
                                :form="form"
                                :genre-options="genreOptions"
                                :artist-types="artistTypes"
                                :max-genres="maxGenres"
                            />
                        </q-card-section>

                        <q-card-actions align="right" class="q-px-md">
                            <Link :href="route('artists.show', artist.id)" class="text-caption text-grey-6 q-mr-md">
                                Cancel
                            </Link>
                            <q-btn
                                unelevated rounded color="primary"
                                label="Save Changes"
                                padding="xs lg"
                                :loading="form.processing"
                                @click="submit"
                            />
                        </q-card-actions>
                    </q-card>

                    <q-card class="post-card q-pb-sm q-mb-xl">
                        <q-card-section>
                            <div class="text-subtitle1 text-weight-medium text-grey-3">Archive this artist</div>
                            <p class="text-grey-7 m-0 text-caption">
                                Archiving hides the identity from the map and profiles for every member.
                                It can be restored later, so nothing is permanently lost.
                            </p>
                        </q-card-section>
                        <q-card-actions align="right" class="q-px-md q-pb-md">
                            <q-btn
                                unelevated rounded color="negative"
                                label="Archive Artist"
                                padding="xs lg"
                                @click="archive"
                            />
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
