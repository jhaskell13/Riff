<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ShowList from '@/Components/ShowList.vue';

// "Your scene": the signed-in home.
//
// Two halves, deliberately. The top is about the bands you chose — what
// they said, when they next play. The bottom is about where you are —
// what's on nearby, whoever is playing it. Both are ordered by date and
// distance; nothing here is ranked by likes, which is the whole reason
// this page replaces the global feed.
const props = defineProps({
    updates: { type: Object, default: () => ({ data: [], nextPage: null, total: 0 }) },
    shows: { type: Array, default: () => [] },
    nearby: { type: Object, default: () => ({ shows: [], listings: [] }) },
    followingCount: { type: Number, default: 0 },
    // "Hartford, CT", or null when the account has never been placed.
    area: { type: String, default: null },
});

const updates = computed(() => props.updates?.data ?? []);

const when = (iso) => (iso
    ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
    : '');

const hasAnything = computed(() => updates.value.length
    || props.shows.length
    || props.nearby.shows.length
    || props.nearby.listings.length);
</script>

<template>
    <Head title="Your scene" />
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">

                    <!-- Following nobody and placed nowhere: there is
                         nothing to compose, so say what to do instead of
                         showing four empty headings. -->
                    <q-card v-if="!hasAnything" class="post-card q-mb-md">
                        <q-card-section>
                            <div class="text-h6 text-weight-bold text-grey-3">Your scene is empty</div>
                            <p class="text-grey-6 q-mt-sm">
                                <span v-if="!followingCount">
                                    Follow a few artists and their updates and shows land here.
                                </span>
                                <span v-else-if="!area">
                                    Add your town in your profile and you'll also see what's on nearby.
                                </span>
                                <span v-else>
                                    Nothing from the artists you follow yet, and nothing coming up near you.
                                </span>
                            </p>
                        </q-card-section>
                        <q-card-actions class="q-px-md q-pb-md">
                            <q-btn
                                unelevated rounded no-caps color="primary"
                                icon="map" label="Find artists on the map"
                                padding="xs lg"
                                @click="$inertia.visit(route('map.index'))"
                            />
                            <q-btn
                                v-if="!area"
                                flat rounded no-caps color="grey-5"
                                label="Set your town"
                                @click="$inertia.visit(route('profile.edit'))"
                            />
                        </q-card-actions>
                    </q-card>

                    <!-- When the artists you follow next play. Above their
                         words on purpose: a date you can still act on
                         beats an update you can only read. -->
                    <q-card v-if="shows.length" class="post-card q-mb-md">
                        <q-card-section>
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-sm">
                                Artists you follow, playing soon
                            </div>
                            <ShowList :shows="shows" empty="Nothing listed yet." />
                        </q-card-section>
                    </q-card>

                    <q-card v-if="updates.length" class="post-card q-mb-md">
                        <q-card-section>
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-sm">
                                From artists you follow
                            </div>
                            <div class="column gap-3">
                                <div v-for="post in updates" :key="post.id" class="update">
                                    <div class="row items-center no-wrap gap-2">
                                        <q-avatar size="30px" color="primary" text-color="white">
                                            <img
                                                v-if="post.artist.imageUrl"
                                                :src="post.artist.imageUrl"
                                                :alt="`${post.artist.name} photo`"
                                            />
                                            <q-icon v-else name="groups" size="16px" />
                                        </q-avatar>
                                        <Link :href="post.artist.url" class="text-body2 text-weight-medium text-grey-2 no-underline">
                                            {{ post.artist.name }}
                                        </Link>
                                        <q-space />
                                        <span class="text-caption text-grey-6">{{ when(post.publishedAt) }}</span>
                                    </div>
                                    <div class="text-body2 text-grey-3 whitespace-pre-line q-mt-xs">{{ post.content }}</div>
                                    <!-- Counts, not controls: reacting and
                                         replying happen on the artist's own
                                         page, where the conversation is. -->
                                    <Link :href="post.artist.url" class="text-caption text-grey-6 q-mt-xs block no-underline">
                                        <q-icon name="favorite_border" size="13px" /> {{ post.likesCount }}
                                        · <q-icon name="chat_bubble_outline" size="13px" /> {{ post.commentsCount }}
                                        · Open {{ post.artist.name }}'s page
                                    </Link>
                                </div>
                            </div>
                            <Link
                                v-if="props.updates.nextPage"
                                :href="route('scene.home', { page: props.updates.nextPage })"
                                class="text-caption text-grey-5 q-mt-sm block"
                            >
                                Older updates →
                            </Link>
                        </q-card-section>
                    </q-card>

                    <q-card v-if="nearby.shows.length" class="post-card q-mb-md">
                        <q-card-section>
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-sm">
                                Coming up{{ area ? ` around ${area}` : ' near you' }}
                            </div>
                            <ShowList :shows="nearby.shows" empty="Nothing listed yet." />
                        </q-card-section>
                    </q-card>

                    <q-card v-if="nearby.listings.length" class="post-card q-mb-xl">
                        <q-card-section>
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-sm">
                                Looking for{{ area ? ` around ${area}` : ' near you' }}
                            </div>
                            <div class="column gap-2">
                                <Link
                                    v-for="listing in nearby.listings"
                                    :key="listing.id"
                                    :href="listing.url"
                                    class="listing-link"
                                >
                                    <q-icon :name="listing.icon" size="18px" color="deep-purple-3" />
                                    <span class="text-body2 text-grey-2">{{ listing.title }}</span>
                                    <q-space />
                                    <span class="text-caption text-grey-6">{{ listing.area }}</span>
                                </Link>
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

.update {
    padding: 12px;
    border-radius: 10px;
    background: #232323;
}

.listing-link {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-radius: 8px;
    background: #262626;
    text-decoration: none;
}

.listing-link:hover {
    background: #2e2e2e;
}

.no-underline {
    text-decoration: none;
}
</style>
