<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CommentThread from '@/Components/Wall/CommentThread.vue';

// The night's thread: "who's going?", "what time are you on?".
//
// Deliberately a flat comment list, not a wall — a show is one subject, so
// the conversation is one conversation. It reuses CommentThread wholesale,
// which is what the polymorphic comments bought: the same replies, likes,
// indentation, hiding and reporting as an artist wall, with no second
// implementation to keep in step.
const props = defineProps({
    show: { type: Object, required: true },
    thread: { type: Object, default: () => ({ comments: [], total: 0 }) },
    // The host artist — whose members own the night and may answer in its
    // voice. Null for an imported show with no host on Riff.
    hostArtist: { type: Object, default: null },
    isAuthenticated: { type: Boolean, default: false },
});

const extraComments = ref([]);
const page = ref(1);
const loading = ref(false);
const allLoaded = ref(false);

const comments = computed(() => [...(props.thread?.comments ?? []), ...extraComments.value]);

const remaining = computed(() => Math.max(0, (props.thread?.total ?? 0) - comments.value.length));

const loadMore = async () => {
    if (loading.value) {
        return;
    }

    loading.value = true;

    try {
        const { data } = await window.axios.get(
            route('shows.comments.index', props.show.id),
            { params: { page: page.value } },
        );

        // Filtered by id, not by offset: the first page came with the page
        // render and the two page sizes differ.
        const seen = new Set(comments.value.map((c) => c.id));
        extraComments.value.push(...data.comments.filter((c) => !seen.has(c.id)));

        if (data.nextPage) {
            page.value = data.nextPage;
        } else {
            allLoaded.value = true;
        }
    } finally {
        loading.value = false;
    }
};

// A stand-in for CommentThread's `post` prop: it only ever uses the id, to
// build the reply route. Shows post replies to their own endpoint.
const subject = computed(() => ({ id: props.show.id }));

const form = useForm({ content: '', parent_id: null, as_artist: false });

const submit = (asArtist) => {
    if (!form.content.trim()) {
        return;
    }

    form.as_artist = asArtist;
    form.post(route('shows.comments.store', props.show.id), {
        preserveScroll: true,
        onSuccess: () => form.reset('content'),
    });
};
</script>

<template>
    <div class="column gap-3">
        <!-- Why it is closed, rather than a missing box: someone who cannot
             tell whether a thread is shut or they are signed out will
             assume Riff is broken. -->
        <p v-if="thread.closedReason" class="text-caption text-grey-6 m-0">
            <q-icon name="lock" size="13px" /> {{ thread.closedReason }}
        </p>

        <div v-else-if="thread.canComment" class="column gap-2">
            <q-input
                v-model="form.content"
                type="textarea"
                dense outlined dark autogrow
                :maxlength="thread.maxComment ?? 2000"
                placeholder="Who's going? Set times? Need a ride?"
                :error="Boolean(form.errors.content)"
                :error-message="form.errors.content"
            />
            <div class="row gap-2">
                <q-btn
                    unelevated rounded no-caps dense
                    color="primary" label="Post" padding="xs md"
                    :disable="!form.content.trim()"
                    :loading="form.processing"
                    @click="submit(false)"
                />
                <q-btn
                    v-if="thread.canCommentAsArtist && hostArtist"
                    outline rounded no-caps dense
                    color="primary"
                    :label="`Answer as ${hostArtist.name}`"
                    padding="xs md"
                    :disable="!form.content.trim()"
                    :loading="form.processing"
                    @click="submit(true)"
                />
            </div>
        </div>

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
            <span class="text-caption text-grey-6">and ask about this show.</span>
        </div>

        <p v-else-if="hostArtist" class="text-caption text-grey-6 m-0">
            Follow {{ hostArtist.name }} to post in this thread.
        </p>

        <div v-if="comments.length" class="column gap-3">
            <CommentThread
                v-for="comment in comments"
                :key="comment.id"
                :comment="comment"
                :post="subject"
                :artist="hostArtist ?? { name: 'the artist' }"
                :can-reply="thread.canComment"
                :can-reply-as-artist="thread.canCommentAsArtist"
                :can-report="isAuthenticated"
                :max-length="thread.maxComment ?? 2000"
                reply-route="shows.comments.store"
            />
            <button
                v-if="remaining > 0 && !allLoaded"
                class="thread-more"
                :disabled="loading"
                @click="loadMore"
            >
                <q-icon name="expand_more" size="16px" />
                {{ loading ? 'Loading…' : `Show ${remaining} more ${remaining === 1 ? 'comment' : 'comments'}` }}
            </button>
        </div>

        <p v-else-if="!thread.closedReason" class="text-caption text-grey-6 m-0">
            Nothing here yet. Ask something about the night.
        </p>
    </div>
</template>

<style scoped>
.thread-more {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    align-self: flex-start;
    background: none;
    border: 0;
    padding: 0;
    color: #90caf9;
    font-size: 0.8rem;
    cursor: pointer;
}

.thread-more:disabled {
    cursor: default;
    opacity: 0.6;
}
</style>
