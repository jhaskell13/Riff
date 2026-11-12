<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import MusicPreview from '@/Components/MusicPreview.vue';
import FollowButton from '@/Components/FollowButton.vue';
import ArtistSafetyMenu from '@/Components/ArtistSafetyMenu.vue';
import ShowList from '@/Components/ShowList.vue';

const props = defineProps({
    artist: {
        type: Object,
        required: true,
    },
    canManage: {
        type: Boolean,
        default: false,
    },
    // False means "not signed in": the page is public, and every
    // account-bound control below swaps for a register prompt.
    canFollow: {
        type: Boolean,
        default: false,
    },
    isFollowing: {
        type: Boolean,
        default: false,
    },
    isBlocked: {
        type: Boolean,
        default: false,
    },
    // Shows this artist has confirmed playing (ShowCardResource).
    upcomingShows: {
        type: Array,
        default: () => [],
    },
    // What this artist is looking for: members, gigs, a room.
    listings: {
        type: Array,
        default: () => [],
    },
    // "Hartford punk", "Everything in Hartford": into the local scene.
    sceneLinks: {
        type: Array,
        default: () => [],
    },
    canBlock: {
        type: Boolean,
        default: false,
    },
});

const links = computed(() => [
    { key: 'website', url: props.artist.website, label: 'Website', icon: 'public' },
    { key: 'bandcamp_url', url: props.artist.bandcamp_url, label: 'Bandcamp', icon: 'album' },
    { key: 'spotify_url', url: props.artist.spotify_url, label: 'Spotify', icon: 'graphic_eq' },
    { key: 'soundcloud_url', url: props.artist.soundcloud_url, label: 'SoundCloud', icon: 'cloud' },
    { key: 'youtube_url', url: props.artist.youtube_url, label: 'YouTube', icon: 'smart_display' },
    { key: 'instagram_url', url: props.artist.instagram_url, label: 'Instagram', icon: 'photo_camera' },
].filter((link) => Boolean(link.url)));

// What a shared link unfurls as.
const metaDescription = computed(() => {
    const where = `${props.artist.type_label} from ${props.artist.address}`;
    const bio = (props.artist.biography || '').trim();

    return bio ? `${where}. ${bio}`.slice(0, 180) : `${where} on Riff.`;
});
</script>

<template>
    <Head :title="artist.name">
        <meta name="description" :content="metaDescription" />
        <meta property="og:title" :content="`${artist.name} — Riff`" />
        <meta property="og:description" :content="metaDescription" />
        <meta property="og:type" content="profile" />
        <meta v-if="artist.image_url" property="og:image" :content="artist.image_url" />
    </Head>
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-center gap-5">
                            <q-avatar size="72px" :color="artist.image_url ? '' : 'primary'" text-color="white">
                                <img v-if="artist.image_url" :src="artist.image_url" :alt="`${artist.name} photo`" />
                                <q-icon v-else name="groups" size="36px" />
                            </q-avatar>
                            <div class="leading-tight">
                                <div class="text-h5 text-weight-bold text-grey-2">{{ artist.name }}</div>
                                <div class="text-caption text-grey-6">
                                    {{ artist.type_label }} • {{ artist.address }}
                                </div>
                                <div v-if="!artist.show_on_map" class="text-caption text-orange-4 q-mt-xs">
                                    <q-icon name="visibility_off" size="14px" /> Hidden from the map
                                </div>
                            </div>
                            <q-card-actions class="ml-auto items-center">
                                <q-btn
                                    v-if="canManage"
                                    unelevated
                                    rounded
                                    color="primary"
                                    label="Edit"
                                    padding="xs lg"
                                    @click="router.visit(route('artists.edit', artist.id))"
                                />
                                <!-- Members get this too: reporting an
                                     identity you share is how a band deals
                                     with a member who has gone rogue. -->
                                <ArtistSafetyMenu
                                    v-if="canFollow"
                                    :artist-id="artist.id"
                                    :artist-name="artist.name"
                                    :is-blocked="isBlocked"
                                    :can-block="canBlock"
                                    color="grey-6"
                                />
                            </q-card-actions>
                        </q-card-section>

                        <q-card-section v-if="artist.genres?.length" class="q-pt-none">
                            <q-chip
                                v-for="genre in artist.genres"
                                :key="genre.id"
                                dense
                                square
                                color="grey-9"
                                text-color="grey-3"
                                class="q-ml-none"
                            >
                                {{ genre.name }}
                            </q-chip>
                        </q-card-section>

                        <q-card-section class="q-pt-none">
                            <FollowButton
                                v-if="canFollow"
                                :artist-id="artist.id"
                                :artist-name="artist.name"
                                :following="isFollowing"
                                :followers-count="artist.followers_count ?? 0"
                            />
                            <!-- Guests: same destination as the map card's
                                 Follow — registration with the fan intent. -->
                            <div v-else class="row items-center no-wrap gap-2">
                                <q-btn
                                    unelevated
                                    rounded
                                    no-caps
                                    color="primary"
                                    icon="add"
                                    label="Join Riff to follow"
                                    padding="xs lg"
                                    :href="route('register', { intent: 'fan' })"
                                    type="a"
                                />
                                <span class="text-caption text-grey-6">
                                    {{ artist.followers_count ?? 0 }} {{ (artist.followers_count ?? 0) === 1 ? 'follower' : 'followers' }}
                                </span>
                            </div>
                        </q-card-section>

                        <q-separator color="grey-9" />

                        <q-card-section v-if="artist.music_embed">
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-sm">Listen</div>
                            <MusicPreview :embed="artist.music_embed" :artist-name="artist.name" />
                        </q-card-section>

                        <q-card-section v-if="upcomingShows.length || canManage">
                            <div class="row items-center q-mb-sm">
                                <div class="text-subtitle2 text-weight-medium text-grey-5">Upcoming shows</div>
                                <q-space />
                                <q-btn
                                    v-if="canManage"
                                    flat dense rounded no-caps
                                    color="primary"
                                    icon="add"
                                    label="List a show"
                                    @click="router.visit(route('shows.create', { artist: artist.id }))"
                                />
                            </div>
                            <ShowList :shows="upcomingShows" empty="Nothing listed yet." />
                        </q-card-section>

                        <q-card-section v-if="listings.length || canManage" class="q-pt-none">
                            <div class="row items-center q-mb-xs">
                                <div class="text-subtitle2 text-weight-medium text-grey-5">Looking for</div>
                                <q-space />
                                <q-btn
                                    v-if="canManage"
                                    flat dense rounded no-caps color="deep-purple-3" icon="add" label="Post a listing"
                                    @click="router.visit(route('listings.create', { artist: artist.id }))"
                                />
                            </div>
                            <div v-if="listings.length" class="column gap-2">
                                <Link v-for="listing in listings" :key="listing.id" :href="listing.url" class="listing-link">
                                    <q-icon :name="listing.icon" size="18px" color="deep-purple-3" />
                                    <span class="text-body2 text-grey-2">{{ listing.title }}</span>
                                    <span class="text-caption text-grey-6">{{ listing.typeLabel }}</span>
                                </Link>
                            </div>
                            <p v-else class="text-caption text-grey-6 m-0">Nothing posted. Need a drummer? Post it here.</p>
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

                        <q-card-section v-if="links.length || artist.email" class="q-pt-none">
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-xs">Links</div>
                            <div class="row gap-2">
                                <q-btn
                                    v-for="link in links"
                                    :key="link.key"
                                    outline
                                    rounded
                                    dense
                                    no-caps
                                    color="grey-5"
                                    padding="xs md"
                                    :icon="link.icon"
                                    :label="link.label"
                                    :href="link.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    type="a"
                                />
                            </div>
                            <div v-if="artist.email" class="text-body2 text-grey-3 q-mt-sm">{{ artist.email }}</div>
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
</style>
