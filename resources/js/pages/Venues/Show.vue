<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ReportDialog from '@/Components/ReportDialog.vue';
import ShowList from '@/Components/ShowList.vue';

const props = defineProps({
    // VenueResource: the address arrives already reduced to what may be
    // public (street for a public venue, town for a private one).
    venue: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
    canReport: { type: Boolean, default: false },
    upcomingShows: { type: Array, default: () => [] },
    sceneLinks: { type: Array, default: () => [] },
});

const reporting = ref(false);

const metaDescription = computed(() => {
    const where = `${props.venue.kindLabel} in ${props.venue.area}`;
    const about = (props.venue.description || '').trim();

    return about ? `${where}. ${about}`.slice(0, 180) : `${where} on Riff.`;
});
</script>

<template>
    <Head :title="venue.name">
        <meta name="description" :content="metaDescription" />
        <meta property="og:title" :content="`${venue.name} — Riff`" />
        <meta property="og:description" :content="metaDescription" />
        <meta property="og:type" content="place" />
    </Head>
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-center gap-5">
                            <q-avatar size="72px" color="primary" text-color="white">
                                <q-icon name="storefront" size="36px" />
                            </q-avatar>
                            <div class="leading-tight">
                                <div class="text-h5 text-weight-bold text-grey-2">{{ venue.name }}</div>
                                <div class="text-caption text-grey-6">
                                    {{ venue.kindLabel }} • {{ venue.area }}
                                </div>
                                <div v-if="!venue.showOnMap" class="text-caption text-orange-4 q-mt-xs">
                                    <q-icon name="visibility_off" size="14px" /> Not on the map
                                </div>
                            </div>
                            <q-card-actions class="ml-auto items-center">
                                <q-btn
                                    v-if="canEdit"
                                    unelevated
                                    rounded
                                    color="primary"
                                    label="Edit"
                                    padding="xs lg"
                                    @click="router.visit(route('venues.edit', venue.id))"
                                />
                                <q-btn
                                    v-if="canReport"
                                    flat
                                    dense
                                    round
                                    size="sm"
                                    icon="flag"
                                    color="grey-6"
                                    :aria-label="`Report ${venue.name}`"
                                    @click="reporting = true"
                                />
                            </q-card-actions>
                        </q-card-section>

                        <q-card-section class="q-pt-none row gap-2">
                            <q-chip
                                v-if="venue.agePolicyLabel"
                                dense
                                square
                                color="grey-9"
                                text-color="grey-3"
                                icon="badge"
                                class="q-ml-none"
                            >
                                {{ venue.agePolicyLabel }}
                            </q-chip>
                        </q-card-section>

                        <q-separator color="grey-9" />

                        <q-card-section>
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-xs">Where</div>
                            <div class="text-body2 text-grey-3">
                                <q-icon name="place" size="16px" class="q-mr-xs" />{{ venue.address }}
                            </div>
                            <!-- A private venue's street was never stored:
                                 say so, so nobody goes hunting for it. -->
                            <div v-if="venue.isPrivate" class="text-caption text-grey-6 q-mt-xs">
                                The address is shared by the hosts, not listed on Riff.
                            </div>
                        </q-card-section>

                        <q-card-section>
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-sm">Upcoming shows</div>
                            <ShowList :shows="upcomingShows" empty="No shows listed here yet." />
                        </q-card-section>

                        <q-card-section v-if="sceneLinks.length" class="q-pt-none">
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-xs">The local scene</div>
                            <div class="row gap-2">
                                <Link
                                    v-for="link in sceneLinks"
                                    :key="link.url"
                                    :href="link.url"
                                    class="scene-link"
                                >
                                    <q-icon name="travel_explore" size="14px" /> {{ link.label }}
                                </Link>
                            </div>
                        </q-card-section>

                        <q-card-section v-if="venue.description">
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-xs">About</div>
                            <div class="text-body2 text-grey-3 whitespace-pre-line">{{ venue.description }}</div>
                        </q-card-section>

                        <q-card-section v-if="venue.website" class="q-pt-none">
                            <q-btn
                                outline
                                rounded
                                dense
                                no-caps
                                color="grey-5"
                                padding="xs md"
                                icon="public"
                                label="Website"
                                :href="venue.website"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                            />
                        </q-card-section>
                    </q-card>
                </div>
            </div>

            <ReportDialog
                v-if="canReport"
                v-model="reporting"
                type="venue"
                :id="venue.id"
                :subject-name="venue.name"
            />
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

.scene-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border: 1px solid #3a3a3a;
    border-radius: 9999px;
    color: #bdbdbd;
    font-size: 0.8rem;
    text-decoration: none;
}

.scene-link:hover {
    border-color: #9e9e9e;
    color: #eee;
}
</style>
