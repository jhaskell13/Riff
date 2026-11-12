<script setup>
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import ArtistProfileFields from '@/Components/ArtistProfileFields.vue'

defineProps({
  genreOptions: { type: Array, default: () => [] },
  artistTypes: { type: Array, default: () => [] },
  maxGenres: { type: Number, default: 3 },
});

const mode = ref('create') // create | join

const form = useForm({
  name: '',
  artist_type: 'band',
  genres: [],
  biography: '',
  hometown: '',
  stateOrProvince: '',
  country: '',
  show_on_map: true,
  music_url: '',
  website: '',
  bandcamp_url: '',
  spotify_url: '',
  soundcloud_url: '',
  youtube_url: '',
  instagram_url: '',
  email: '',
  photo: null,
});

// Join mode points at listings; the join-request workflow is Phase 4.

const clearInputs = () => {
    form.reset();
}
</script>

<template>
  <AuthenticatedLayout>
    <q-page class="page-bg">
      <div class="row justify-center">
        <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">

          <q-card class="post-card q-pb-md q-mb-xl">

            <!-- Header -->
            <q-card-section class="row flex-nowrap! items-center gap-5">
                <q-avatar class="self-start q-mt-sm" size="48px" color="primary" text-color="white">
                    <q-icon v-if="mode === 'create'" name="add_to_queue" size="24px" />
                    <q-icon v-else name="queue_music" size="24px" />
                </q-avatar>

                <div class="leading-tight">
                    <div class="text-h6 text-weight-bold text-grey-3">
                        Create your own Artist Identity, or join an existing one!
                    </div>
                    <p class="text-grey-7 m-0">
                        You can create your own artist identity, or join an existing artist/band by requesting.
                        Note: Only artists actively looking for members or accepting requests will be shown.
                    </p>
                </div>
            </q-card-section>

            <q-separator color="grey-9" />

            <q-card-section class="pb-0!">
                <!-- Segmented control -->
                <q-btn-toggle
                    v-model="mode"
                    rounded
                    spread
                    unelevated
                    text-color="grey-5"
                    toggle-color="primary"
                    class="mx-auto w-[75%] border border-gray-500"
                    :options="[
                        { label: 'Create Artist Identity', value: 'create' },
                        { label: 'Join an existing band', value: 'join' }
                    ]"
                />
            </q-card-section>

            <!-- Form Fields -->
            <q-card-section>

              <!-- CREATE MODE -->
              <div v-if="mode === 'create'">
                <q-file
                  dark
                  v-model="form.photo"
                  label="Photo (optional)"
                  accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                  clearable
                  rounded
                  outlined
                  bg-color="grey-9"
                  color="grey-4"
                  label-color="grey-5"
                  hint="Location data and other metadata are removed before it is saved."
                  class="q-mb-md"
                  :error="!!form.errors.photo"
                  :error-message="form.errors.photo"
                >
                  <template #prepend>
                    <q-icon name="photo_camera" />
                  </template>
                </q-file>

                <ArtistProfileFields
                  :form="form"
                  :genre-options="genreOptions"
                  :artist-types="artistTypes"
                  :max-genres="maxGenres"
                />
              </div>

              <!-- JOIN MODE: joining an existing artist (request + approval) is
                   Phase 4. Until then, bands looking for members post
                   listings, and this points at them. -->
              <div v-else class="text-grey-4">
                <p class="text-body2 m-0">
                  Bands looking for members post it on Riff. Find one near you, reply, and once you're in,
                  any member can add you to the artist.
                </p>
                <div class="row gap-2 q-mt-md">
                  <q-btn
                    unelevated rounded no-caps color="deep-purple-5" icon="campaign"
                    label="Bands looking for members near me" padding="xs lg"
                    :href="route('map.index', { kinds: 'listings' })" type="a"
                  />
                  <q-btn
                    outline rounded no-caps color="grey-4" icon="person_search"
                    label="Post that I'm looking for a band" padding="xs lg"
                    :href="route('listings.create', { type: 'band_wanted' })" type="a"
                  />
                </div>
              </div>

            </q-card-section>

            <!-- Actions -->
            <q-card-actions align="right" class="q-px-md q-pb-md q-pt-none">
              <q-btn
                v-if="mode === 'create'"
                flat
                rounded
                dense
                label="Clear"
                color="grey-5"
                class="q-mr-sm"
                @click="clearInputs"
              />

              <!-- Create button -->
              <q-btn
                v-if="mode === 'create'"
                unelevated
                rounded
                color="primary"
                label="Create"
                padding="xs lg"
                :loading="form.processing"
                @click="form.post(route('artists.store'))"
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

.rounded-borders {
  border-radius: 10px;
}
</style>
