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
const search = ref('')
const searchResults = ref([])
const selectedArtistId = ref(null);

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

// Join mode is still a stub: the join-request workflow is Phase 3 roadmap
// work, so nothing here calls the server yet.

const clearInputs = () => {
    form.reset();
    search.value = '';
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
                        { label: 'Request To Join Artist', value: 'join' }
                    ]"
                />
            </q-card-section>

            <!-- Form Fields -->
            <q-card-section>

              <!-- CREATE MODE -->
              <div v-if="mode === 'create'">
                <q-file
                  v-model="form.photo"
                  label="Photo (optional)"
                  accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                  clearable
                  rounded
                  outlined
                  bg-color="grey-8"
                  color="grey-4"
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

              <!-- JOIN MODE -->
              <div v-else>

                <q-input
                  v-model="search"
                  label="Search for an artist"
                  rounded
                  outlined
                  bg-color="grey-8"
                  color="grey-4"
                  debounce="200"
                />

                <div v-if="searchResults.length" class="q-mt-sm">
                  <q-list class="rounded-borders">
                    <q-item
                      v-for="artist in searchResults"
                      :key="artist.id"
                      clickable
                      v-ripple
                      @click="selectedArtistId = artist.id"
                    >
                      <q-item-section>
                        <div class="text-grey-3 text-weight-medium">
                          {{ artist.name }}
                        </div>
                        <div class="text-grey-6 text-caption">
                          {{ artist.hometown }}
                        </div>
                      </q-item-section>

                      <q-item-section side>
                        <q-radio
                          v-model="selectedArtistId"
                          :val="artist.id"
                          color="primary"
                        />
                      </q-item-section>
                    </q-item>
                  </q-list>
                </div>

                <div v-else class="text-grey-6 text-caption">
                  Type at least 2 characters to search.
                </div>

              </div>

            </q-card-section>

            <!-- Actions -->
            <q-card-actions align="right" class="q-px-md q-pb-md q-pt-none">
              <q-btn
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

              <!-- Request to join button -->
              <q-btn
                v-else
                unelevated
                rounded
                color="primary"
                label="Send Request"
                padding="xs lg"
                :disable="!selectedArtistId"
                :loading="form.processing"
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
