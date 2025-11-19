<script setup>
import { useForm, Link } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    artists: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row !flex-nowrap items-center gap-5">
                            <q-avatar class="self-start q-mt-sm" size="48px" color="primary" text-color="white">
                                <q-icon name="queue_music" size="24px" />
                            </q-avatar>
                            <div class="leading-tight">
                                <div class="text-h6 text-weight-bold text-grey-3">
                                    Your Artist Identities
                                </div>
                                <!-- <p class="text-grey-7 m-0">
                                    You can create your own artist identity, or join an existing artist/band by requesting.
                                    Note: Only artists actively looking for members or accepting requests will be shown.
                                </p> -->
                            </div>
                        </q-card-section>
                        <q-separator color="grey-9" />
                        <q-card-section>
                            <div
                                v-for="artist in artists"
                                :key="artist.id"
                                class="flex mt-5 first:mt-0"
                            >
                                <q-avatar size="48px" :color="artist.image_url ? '' : 'primary'" text-color="white">
                                    <img v-if="artist.image_url" :src="artist.image_url" />
                                    <q-icon v-else name="groups" />
                                </q-avatar>
                                <div class="col q-ml-md">
                                    <div class="text-subtitle1 text-weight-medium text-grey-2">{{ artist.name }}</div>
                                    <div class="text-caption text-grey-6">{{ artist.hometown }}, {{ artist.stateOrProvince }} • {{ artist.genre }}</div>
                                </div>
                                <q-separator color="grey-9" />
                            </div>
                        </q-card-section>
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
