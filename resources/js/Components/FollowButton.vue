<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Shared by the artist profile and (from Step 4) the map card, so the two
// behave identically. The server is the source of truth; this only keeps
// the button from feeling laggy while the request is in flight.
const props = defineProps({
    artistId: { type: Number, required: true },
    artistName: { type: String, default: 'this artist' },
    following: { type: Boolean, default: false },
    followersCount: { type: Number, default: null },
    dense: { type: Boolean, default: false },
});

const emit = defineEmits(['changed']);

const pending = ref(false);
const optimistic = ref(null);

const isFollowing = computed(() => optimistic.value ?? props.following);

const displayCount = computed(() => {
    if (props.followersCount === null) {
        return null;
    }

    // Reflect the pending change so the count does not lag the button.
    const drift = optimistic.value === null || optimistic.value === props.following
        ? 0
        : (optimistic.value ? 1 : -1);

    return Math.max(0, props.followersCount + drift);
});

const toggle = () => {
    if (pending.value) {
        return;
    }

    const next = !isFollowing.value;
    optimistic.value = next;
    pending.value = true;

    const options = {
        preserveScroll: true,
        // The map owns a Leaflet instance and a viewport; re-mounting it
        // on every follow would throw both away.
        preserveState: true,
        onFinish: () => {
            pending.value = false;
        },
        onError: () => {
            optimistic.value = null;
        },
        onSuccess: () => {
            optimistic.value = null;
            emit('changed', props.artistId);
        },
    };

    if (next) {
        router.post(route('artists.follow', props.artistId), {}, options);
    } else {
        router.delete(route('artists.unfollow', props.artistId), options);
    }
};
</script>

<template>
    <div class="row items-center no-wrap gap-2">
        <q-btn
            unelevated
            rounded
            :outline="isFollowing"
            :color="isFollowing ? 'grey-5' : 'primary'"
            :label="isFollowing ? 'Following' : 'Follow'"
            :icon="isFollowing ? 'check' : 'add'"
            :padding="dense ? 'xs md' : 'xs lg'"
            :dense="dense"
            :loading="pending"
            :aria-pressed="isFollowing"
            :aria-label="isFollowing ? `Unfollow ${artistName}` : `Follow ${artistName}`"
            @click.stop="toggle"
        />
        <span v-if="displayCount !== null" class="text-caption text-grey-6">
            {{ displayCount }} {{ displayCount === 1 ? 'follower' : 'followers' }}
        </span>
    </div>
</template>
