<script setup>
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ReportDialog from '@/Components/ReportDialog.vue';

// One comment and its replies. Replies are one level deep, so this renders
// a comment and a flat list under it — never recursively.
const props = defineProps({
    comment: { type: Object, required: true },
    post: { type: Object, required: true },
    artist: { type: Object, required: true },
    canReply: { type: Boolean, default: false },
    canReplyAsArtist: { type: Boolean, default: false },
    canReport: { type: Boolean, default: false },
    maxLength: { type: Number, default: 2000 },
});

const replying = ref(false);
const reporting = ref(false);
const replyForm = useForm({ content: '', parent_id: props.comment.id, as_artist: false });

const submitReply = (asArtist) => {
    if (!replyForm.content.trim()) {
        return;
    }

    replyForm.as_artist = asArtist;
    replyForm.post(route('wall.comments.store', props.post.id), {
        preserveScroll: true,
        onSuccess: () => {
            replyForm.reset('content');
            replying.value = false;
        },
    });
};

const toggleLike = (comment) => {
    const options = { preserveScroll: true, preserveState: true };

    if (comment.isLiked) {
        router.delete(route('wall.comments.unlike', comment.id), options);
    } else {
        router.post(route('wall.comments.like', comment.id), {}, options);
    }
};

const remove = (comment) => {
    if (confirm('Delete this comment?')) {
        router.delete(route('wall.comments.destroy', comment.id), { preserveScroll: true });
    }
};

const authorName = (comment) => (comment.isArtist
    ? (comment.artist?.name ?? 'The artist')
    : (comment.user?.name ?? 'Someone'));
</script>

<template>
    <div class="comment">
        <div class="row items-start no-wrap gap-2">
            <q-avatar size="26px" :color="comment.isArtist ? 'primary' : 'grey-8'" text-color="white">
                <img v-if="comment.isArtist && comment.artist?.image_url" :src="comment.artist.image_url" alt="" />
                <q-icon v-else :name="comment.isArtist ? 'groups' : 'person'" size="15px" />
            </q-avatar>
            <div class="col">
                <div class="row items-center gap-2">
                    <span class="text-body2 text-weight-medium text-grey-3">{{ authorName(comment) }}</span>
                    <!-- The roadmap asks for the artist's replies to be
                         marked. This badge reads from stored state, not a
                         name comparison, so it can be trusted. -->
                    <q-badge v-if="comment.isArtist" color="primary" class="text-caption">
                        {{ artist.name }}
                    </q-badge>
                </div>
                <div class="text-body2 text-grey-3 whitespace-pre-line">{{ comment.content }}</div>
                <div class="row items-center gap-3 q-mt-xs">
                    <button
                        v-if="comment.isLiked !== null"
                        class="comment-action"
                        :class="{ 'comment-action--on': comment.isLiked }"
                        :aria-pressed="comment.isLiked"
                        @click="toggleLike(comment)"
                    >
                        <q-icon :name="comment.isLiked ? 'favorite' : 'favorite_border'" size="14px" />
                        <span v-if="comment.likesCount">{{ comment.likesCount }}</span>
                    </button>
                    <span v-else-if="comment.likesCount" class="comment-action">
                        <q-icon name="favorite_border" size="14px" /> {{ comment.likesCount }}
                    </span>
                    <button v-if="canReply" class="comment-action" @click="replying = !replying">
                        Reply
                    </button>
                    <button v-if="comment.canDelete" class="comment-action" @click="remove(comment)">
                        Delete
                    </button>
                    <button
                        v-if="canReport && !comment.canDelete"
                        class="comment-action"
                        @click="reporting = true"
                    >
                        Report
                    </button>
                </div>

                <!-- Replies: one level, flat. -->
                <div v-if="comment.replies?.length" class="column gap-2 q-mt-sm reply-list">
                    <CommentThread
                        v-for="reply in comment.replies"
                        :key="reply.id"
                        :comment="reply"
                        :post="post"
                        :artist="artist"
                        :can-reply="false"
                        :can-report="canReport"
                    />
                </div>
                <div
                    v-if="comment.repliesCount > (comment.replies?.length ?? 0)"
                    class="text-caption text-grey-6 q-mt-xs"
                >
                    {{ comment.repliesCount - (comment.replies?.length ?? 0) }} more
                    {{ comment.repliesCount - (comment.replies?.length ?? 0) === 1 ? 'reply' : 'replies' }}
                </div>

                <div v-if="replying" class="column gap-2 q-mt-sm">
                    <q-input
                        v-model="replyForm.content"
                        type="textarea"
                        dense outlined dark autogrow
                        :maxlength="maxLength"
                        :placeholder="`Reply to ${authorName(comment)}…`"
                        :error="Boolean(replyForm.errors.content)"
                        :error-message="replyForm.errors.content"
                    />
                    <div class="row gap-2">
                        <q-btn
                            unelevated rounded no-caps dense
                            color="primary" label="Reply" padding="xs md"
                            :disable="!replyForm.content.trim()"
                            :loading="replyForm.processing"
                            @click="submitReply(false)"
                        />
                        <q-btn
                            v-if="canReplyAsArtist"
                            outline rounded no-caps dense
                            color="primary"
                            :label="`Reply as ${artist.name}`"
                            padding="xs md"
                            :disable="!replyForm.content.trim()"
                            :loading="replyForm.processing"
                            @click="submitReply(true)"
                        />
                        <q-btn flat rounded no-caps dense color="grey-6" label="Cancel" @click="replying = false" />
                    </div>
                </div>
            </div>
        </div>

        <ReportDialog
            v-if="canReport"
            v-model="reporting"
            type="comment"
            :id="comment.id"
            :subject-name="`this comment by ${authorName(comment)}`"
        />
    </div>
</template>

<style scoped>
.comment {
    padding: 2px 0;
}

.reply-list {
    padding-left: 10px;
    border-left: 2px solid #2e2e2e;
}

.comment-action {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: none;
    border: 0;
    padding: 0;
    color: #8d8d8d;
    font-size: 0.75rem;
    cursor: pointer;
}

.comment-action:hover {
    color: #d8d8d8;
}

.comment-action--on {
    color: #ef5350;
}
</style>
