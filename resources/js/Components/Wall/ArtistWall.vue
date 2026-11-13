<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
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
    // Only populated for members: who they have barred from this wall.
    wallBans: { type: Array, default: () => [] },
    canModerate: { type: Boolean, default: false },
});

// Members' own list, so a ban is liftable where it was made rather than
// only from wherever the post happened to be.
const showingBans = ref(false);

const unban = (userId) => {
    router.delete(route('wall.bans.destroy', [props.artist.id, userId]), { preserveScroll: true });
};

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

        <!-- A member's view of their own moderation. Everything here is
             reversible; nothing here deletes anything. -->
        <div v-if="canModerate && wallBans.length" class="bans">
            <button class="bans__toggle" @click="showingBans = !showingBans">
                <q-icon :name="showingBans ? 'expand_less' : 'expand_more'" size="16px" />
                {{ wallBans.length }} {{ wallBans.length === 1 ? 'person' : 'people' }} removed from your wall
            </button>
            <div v-if="showingBans" class="column gap-2 q-mt-sm">
                <div v-for="ban in wallBans" :key="ban.userId" class="row items-center gap-2">
                    <q-icon name="person_off" size="16px" color="grey-6" />
                    <span class="text-body2 text-grey-3">{{ ban.name }}</span>
                    <span v-if="ban.reason" class="text-caption text-grey-6">“{{ ban.reason }}”</span>
                    <q-space />
                    <q-btn
                        flat dense no-caps rounded size="sm"
                        color="orange-4"
                        label="Allow back"
                        @click="unban(ban.userId)"
                    />
                </div>
            </div>
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

<style scoped>
.bans {
    padding: 10px 12px;
    border: 1px solid #3a3a3a;
    border-radius: 8px;
    background: #1c1c1c;
}

.bans__toggle {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: none;
    border: 0;
    padding: 0;
    color: #9e9e9e;
    font-size: 0.78rem;
    cursor: pointer;
}

.bans__toggle:hover {
    color: #e0e0e0;
}
</style>
