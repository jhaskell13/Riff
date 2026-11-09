<script setup>
import { Link } from '@inertiajs/vue3';
import MusicPreview from '@/Components/MusicPreview.vue';
import FollowButton from '@/Components/FollowButton.vue';

/**
 * The expanded card for the selected artist: enough to decide whether to
 * listen, follow, or open the full profile, without leaving the map.
 */
defineProps({
    card: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    failed: { type: Boolean, default: false },
    canFollow: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'followed', 'collapse']);
</script>

<template>
    <div class="flex h-full flex-col bg-riff-surface">
        <div class="flex items-center justify-between gap-1 border-b border-riff-line px-2 py-2">
            <q-btn flat dense no-caps icon="arrow_back" label="Back to list" color="grey-5" @click="emit('close')" />
            <q-btn
                flat dense round size="sm" color="grey-5"
                class="collapse-btn"
                aria-label="Hide the artist panel"
                @click="emit('collapse')"
            >
                <q-icon class="collapse-btn__wide" name="chevron_left" size="20px" />
                <q-icon class="collapse-btn__narrow" name="expand_more" size="20px" />
                <q-tooltip>Hide panel</q-tooltip>
            </q-btn>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <div v-if="loading" class="p-4">
                <q-skeleton type="rect" height="120px" dark />
                <q-skeleton type="text" width="50%" class="mt-3" dark />
                <q-skeleton type="text" width="70%" dark />
            </div>

            <div v-else-if="failed" class="p-6 text-center">
                <q-icon name="cloud_off" size="28px" class="text-riff-muted" />
                <p class="mt-2 text-sm text-riff-text">Couldn't load this artist.</p>
            </div>

            <div v-else-if="card" class="p-4">
                <div class="flex items-start gap-3">
                    <q-avatar size="64px" class="shrink-0" :color="card.imageUrl ? '' : 'grey-9'" text-color="grey-5">
                        <img v-if="card.imageUrl" :src="card.imageUrl" :alt="`${card.name} photo`" />
                        <q-icon v-else name="music_note" size="30px" />
                    </q-avatar>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg leading-tight font-bold break-words text-riff-text">{{ card.name }}</h2>
                        <p class="text-xs break-words text-riff-muted">
                            {{ card.typeLabel }}
                            <template v-if="card.distance !== null">
                                · {{ card.distance }} {{ card.unit }} away
                            </template>
                        </p>
                        <p class="text-xs break-words text-riff-muted">{{ card.location }}</p>
                    </div>
                </div>

                <div v-if="card.genres?.length" class="mt-3 flex flex-wrap gap-1">
                    <span
                        v-for="genre in card.genres"
                        :key="genre"
                        class="rounded-full bg-riff-surface-2 px-2 py-0.5 text-xs text-riff-muted"
                    >
                        {{ genre }}
                    </span>
                </div>

                <div class="mt-4">
                    <FollowButton
                        v-if="canFollow"
                        :artist-id="card.id"
                        :artist-name="card.name"
                        :following="card.isFollowing"
                        :followers-count="card.followersCount"
                        dense
                        @changed="emit('followed', card.id)"
                    />
                    <div v-else class="flex items-center gap-2">
                        <q-btn
                            unelevated rounded no-caps dense color="primary" padding="xs md"
                            icon="add" label="Follow"
                            :href="route('login')" type="a"
                        >
                            <q-tooltip>Sign in to follow {{ card.name }}</q-tooltip>
                        </q-btn>
                        <span class="text-xs text-riff-muted">
                            {{ card.followersCount }} {{ card.followersCount === 1 ? 'follower' : 'followers' }}
                        </span>
                    </div>
                </div>

                <div v-if="card.music" class="mt-4">
                    <MusicPreview :embed="card.music" :artist-name="card.name" dense />
                </div>
                <p v-else class="mt-4 text-xs text-riff-muted">No music linked yet.</p>

                <p v-if="card.bio" class="mt-4 text-sm leading-relaxed whitespace-pre-line text-riff-text">
                    {{ card.bio }}
                </p>

                <Link :href="card.url" class="mt-4 inline-block text-sm text-riff-primary hover:underline">
                    View full profile →
                </Link>
            </div>
        </div>
    </div>
</template>

<style scoped>
.collapse-btn__narrow {
    display: none;
}

@media (max-width: 1023px) {
    .collapse-btn__wide {
        display: none;
    }

    .collapse-btn__narrow {
        display: inline-flex;
    }
}
</style>
