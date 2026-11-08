<script setup>
import { useForm, Link } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const mode = ref('create') // create | join
const search = ref('')
const searchResults = ref([])
const selectedArtistId = ref(null);

const form = useForm({
  name: '',
  genre: '',
  biography: '',
  hometown: '',
  stateOrProvince: '',
  country: '',
});

const genres = [
  'Rock', 'Indie', 'Alternative', 'Metal', 'Pop',
  'Hip-Hop', 'Electronic', 'Jazz', 'Folk', 'Other'
]

// Fake search — replace with API endpoint later
watch(search, async (val) => {
  if (!val || val.length < 2) {
    searchResults.value = []
    return
  }

//   try {
//     const res = await fetch(`/api/bands/search?q=${encodeURIComponent(val)}`)
//     if (!res.ok) {
//       searchResults.value = []
//       return
//     }
//     searchResults.value = await res.json()
//   } catch (e) {
//     searchResults.value = []
//   }
});

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
            <q-card-section class="row !flex-nowrap items-center gap-5">
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

            <q-card-section class="!pb-0">
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
            <q-card-section class="q-gutter-md">

              <!-- CREATE MODE -->
              <div v-if="mode === 'create'">

                <q-input
                    v-model="form.name"
                    label="Artist Name"
                    rounded
                    outlined
                    bg-color="grey-8"
                    color="grey-4"
                    :error="!!form.errors.name"
                    :error-message="form.errors.name"
                />

                <q-select
                  v-model="form.genre"
                  :options="genres"
                  label="Genre"
                  rounded
                  outlined
                  bg-color="grey-8"
                  color="grey-4"
                  emit-value
                  map-options
                  :error="!!form.errors.genre"
                  :error-message="form.errors.genre"
                />

                <q-input
                  v-model="form.hometown"
                  label="Hometown"
                  rounded
                  outlined
                  bg-color="grey-8"
                  color="grey-4"
                  :error="!!form.errors.hometown"
                  :error-message="form.errors.hometown"
                />

                <q-input
                  v-model="form.stateOrProvince"
                  label="State/Province"
                  rounded
                  outlined
                  bg-color="grey-8"
                  color="grey-4"
                  :error="!!form.errors.stateOrProvince"
                  :error-message="form.errors.stateOrProvince"
                />

                <q-input
                  v-model="form.country"
                  label="Country"
                  rounded
                  outlined
                  bg-color="grey-8"
                  color="grey-4"
                  :error="!!form.errors.country"
                  :error-message="form.errors.country"
                />

                <q-input
                  v-model="form.biography"
                  label="Artist biography"
                  type="textarea"
                  autogrow
                  rounded
                  outlined
                  bg-color="grey-8"
                  color="grey-4"
                  :error="!!form.errors.biography"
                  :error-message="form.errors.biography"
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
                          {{ artist.genre }} • {{ artist.hometown }}
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
                @click=""
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
