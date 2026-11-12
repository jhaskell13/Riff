<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import WallComposer from '@/Components/Wall/WallComposer.vue';
import WallPost from '@/Components/Wall/WallPost.vue';

// The artist's page as the fans' home: updates from the band and posts
// from fans in one stream, newest first. Ordered by time, never by likes —
// a new band's post is as visible as a popular one's.
const props = defineProps({
    artist: { type: Object, required: true },
    wall: { type: Object, default: () => ({ posts: [], total: 0 }) },
    wallMode: { type: String, default: 'fans' },
    canPostAsArtist: { type: Boolean, default: false },
    canPostOnWall: { type: Boolean, default: false },
    // False means "not signed in": every control swaps for a register
    // prompt, as the map card and follow button already do.
    isAuthenticated: { type: Boolean, default: false },
    isFollowing: { type: Boolean, default: false },
    maxPost: { type: Number, default: 5000 },
    maxComment: { type: Number, default: 2000 },
});

const posts = computed(() => props.wall?.posts ?? []);

// Why this viewer can't post. Saying so beats hiding the box: a listener
// who can't tell whether a wall is closed or they're just not a fan will
// assume Riff is broken.
const closedReason = computed(() => {
    if (props.canPostOnWall) {
        return null;
    }

    if (!props.isAuthenticated) {
        return null;
    }

    if (props.wallMode === 'members_only') {
        return `${props.artist.name} keeps their wall closed — only the band posts here.`;
    }

    if (props.wallMode === 'fans' && !props.isFollowing) {
        return `Follow ${props.artist.name} to post on their wall.`;
    }

    return null;
});
</script>

<template>
    <div class="column gap-3">
        <WallComposer
            v-if="canPostOnWall"
            :artist="artist"
            :can-post-as-artist="canPostAsArtist"
            :can-post-on-wall="canPostOnWall"
            :max-length="maxPost"
        />

        <p v-else-if="closedReason" class="text-caption text-grey-6 m-0">
            {{ closedReason }}
        </p>

        <!-- Guests: the same destination as every other call to action. -->
        <div v-else-if="!isAuthenticated" class="row items-center gap-2">
            <q-btn
                unelevated rounded no-caps
                color="primary"
                icon="add"
                label="Join Riff to post"
                padding="xs lg"
                :href="route('register', { intent: 'fan' })"
                type="a"
            />
            <span class="text-caption text-grey-6">and talk with other fans here.</span>
        </div>

        <div v-if="posts.length" class="column gap-3">
            <WallPost
                v-for="post in posts"
                :key="post.id"
                :post="post"
                :artist="artist"
                :can-post-as-artist="canPostAsArtist"
                :can-report="isAuthenticated"
                :max-length="maxComment"
            />
        </div>

        <p v-else class="text-caption text-grey-6 m-0">
            Nothing here yet.
            <span v-if="canPostAsArtist">Tell your fans what you're working on.</span>
            <span v-else-if="canPostOnWall">Be the first to say something.</span>
        </p>

        <!-- Plain pagination: a wall is a place you visit, not a feed you
             fall into, so there is no infinite scroll here on purpose. -->
        <Link
            v-if="wall?.nextPage"
            :href="route('artists.show', [artist.slug, { page: wall.nextPage }])"
            preserve-scroll
            class="text-caption text-grey-5"
        >
            Older posts →
        </Link>
    </div>
</template>
