<script setup>
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({
    artist: {
        type: Object,
        required: true,
    },
    canManage: {
        type: Boolean,
        default: false,
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
                            <q-avatar size="72px" :color="artist.image_url ? '' : 'primary'" text-color="white">
                                <img v-if="artist.image_url" :src="artist.image_url" />
                                <q-icon v-else name="groups" size="36px" />
                            </q-avatar>
                            <div class="leading-tight">
                                <div class="text-h5 text-weight-bold text-grey-2">{{ artist.name }}</div>
                                <div class="text-caption text-grey-6">
                                    {{ artist.genre }} • {{ artist.address }}
                                </div>
                            </div>
                            <q-card-actions v-if="canManage" class="ml-auto">
                                <q-btn
                                    unelevated
                                    rounded
                                    color="primary"
                                    label="Edit"
                                    padding="xs lg"
                                    @click="router.visit(route('artists.edit', artist.id))"
                                />
                            </q-card-actions>
                        </q-card-section>

                        <q-separator color="grey-9" />

                        <q-card-section v-if="artist.biography">
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-xs">About</div>
                            <div class="text-body2 text-grey-3 whitespace-pre-line">{{ artist.biography }}</div>
                        </q-card-section>

                        <q-card-section>
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-xs">Members</div>
                            <div class="text-body2 text-grey-3">
                                <span v-for="(member, index) in artist.members" :key="member.id">
                                    {{ member.name }}{{ index < artist.members.length - 1 ? ', ' : '' }}
                                </span>
                            </div>
                        </q-card-section>

                        <q-card-section v-if="artist.website || artist.email" class="q-pt-none">
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-xs">Links</div>
                            <div v-if="artist.website" class="text-body2">
                                <a :href="artist.website" target="_blank" rel="noopener noreferrer" class="text-primary">
                                    {{ artist.website }}
                                </a>
                            </div>
                            <div v-if="artist.email" class="text-body2 text-grey-3">{{ artist.email }}</div>
                        </q-card-section>

                        <q-card-section class="q-pt-none">
                            <Link :href="route('artists.index')" class="text-caption text-grey-6">
                                &larr; Back to your artists
                            </Link>
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
</style>
