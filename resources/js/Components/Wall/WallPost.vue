<script setup>
import { useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CommentThread from '@/Components/Wall/CommentThread.vue';
import ReportDialog from '@/Components/ReportDialog.vue';

const props = defineProps({
    post: { type: Object, required: true },
    artist: { type: Object, required: true },
    canPostAsArtist: { type: Boolean, default: false },
    canReport: { type: Boolean, default: false },
    maxLength: { type: Number, default: 2000 },
});

const commenting = ref(false);
const reporting = ref(false);
const commentForm = useForm({ content: '', parent_id: null, as_artist: false });

// An artist-voice post never carries a user: the band said it, and which
// member typed it is not published (PostResource enforces this server-side
// — this is only the rendering of that decision).
const isArtistVoice = computed(() => props.post.authorContext === 'artist');

const authorName = computed(() => (isArtistVoice.value
    ? (props.post.artist?.name ?? props.artist.name)
    : (props.post.user?.name ?? 'Someone')));

const when = computed(() => {
    const iso = props.post.publishedAt ?? props.post.published_at;

    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
    });
});

const submitComment = (asArtist) => {
    if (!commentForm.content.trim()) {
        return;
    }

    commentForm.as_artist = asArtist;
    commentForm.post(route('wall.comments.store', props.post.id), {
        preserveScroll: true,
        onSuccess: () => {
            commentForm.reset('content');
            commenting.value = false;
        },
    });
};

const toggleLike = () => {
    const options = { preserveScroll: true, preserveState: true };

    if (props.post.isLiked) {
        router.delete(route('wall.posts.unlike', props.post.id), options);
    } else {
        router.post(route('wall.posts.like', props.post.id), {}, options);
    }
};

const remove = () => {
    if (confirm('Delete this post?')) {
        router.delete(route('wall.posts.destroy', props.post.id), { preserveScroll: true });
    }
};
</script>

<template>
    <div class="wall-post column gap-2">
        <div class="row items-center no-wrap gap-2">
            <q-avatar size="34px" :color="isArtistVoice ? 'primary' : 'grey-8'" text-color="white">
                <img
                    v-if="isArtistVoice && post.artist?.image_url"
                    :src="post.artist.image_url"
                    :alt="`${authorName} photo`"
                />
                <q-icon v-else :name="isArtistVoice ? 'groups' : 'person'" size="18px" />
            </q-avatar>
            <div class="leading-tight">
                <div class="row items-center gap-2">
                    <span class="text-body2 text-weight-medium text-grey-2">{{ authorName }}</span>
                    <q-badge v-if="isArtistVoice" color="primary" class="text-caption">Artist</q-badge>
                </div>
                <div class="text-caption text-grey-6">{{ when }}</div>
            </div>
        </div>

        <div class="text-body2 text-grey-3 whitespace-pre-line">{{ post.content }}</div>

        <div class="row items-center gap-4">
            <button
                v-if="post.isLiked !== null"
                class="post-action"
                :class="{ 'post-action--on': post.isLiked }"
                :aria-pressed="post.isLiked"
                :aria-label="post.isLiked ? 'Remove your like' : 'Like this post'"
                @click="toggleLike"
            >
                <q-icon :name="post.isLiked ? 'favorite' : 'favorite_border'" size="16px" />
                <span v-if="post.likesCount">{{ post.likesCount }}</span>
            </button>
            <span v-else-if="post.likesCount" class="post-action">
                <q-icon name="favorite_border" size="16px" /> {{ post.likesCount }}
            </span>

            <button v-if="post.canComment" class="post-action" @click="commenting = !commenting">
                <q-icon name="chat_bubble_outline" size="16px" />
                <span>{{ post.commentsCount || 'Reply' }}</span>
            </button>
            <span v-else-if="post.commentsCount" class="post-action">
                <q-icon name="chat_bubble_outline" size="16px" /> {{ post.commentsCount }}
            </span>

            <q-space />

            <button v-if="post.canDelete" class="post-action" @click="remove">Delete</button>
            <button v-if="canReport && !post.canDelete" class="post-action" @click="reporting = true">
                Report
            </button>
        </div>

        <div v-if="commenting" class="column gap-2">
            <q-input
                v-model="commentForm.content"
                type="textarea"
                dense outlined dark autogrow
                :maxlength="maxLength"
                placeholder="Say something…"
                :error="Boolean(commentForm.errors.content)"
                :error-message="commentForm.errors.content"
            />
            <div class="row gap-2">
                <q-btn
                    unelevated rounded no-caps dense
                    color="primary" label="Post" padding="xs md"
                    :disable="!commentForm.content.trim()"
                    :loading="commentForm.processing"
                    @click="submitComment(false)"
                />
                <q-btn
                    v-if="canPostAsArtist"
                    outline rounded no-caps dense
                    color="primary"
                    :label="`Reply as ${artist.name}`"
                    padding="xs md"
                    :disable="!commentForm.content.trim()"
                    :loading="commentForm.processing"
                    @click="submitComment(true)"
                />
                <q-btn flat rounded no-caps dense color="grey-6" label="Cancel" @click="commenting = false" />
            </div>
        </div>

        <div v-if="post.comments?.length" class="column gap-3 q-mt-xs">
            <CommentThread
                v-for="comment in post.comments"
                :key="comment.id"
                :comment="comment"
                :post="post"
                :artist="artist"
                :can-reply="post.canComment"
                :can-reply-as-artist="canPostAsArtist"
                :can-report="canReport"
                :max-length="maxLength"
            />
            <div
                v-if="post.commentsCount > post.comments.length"
                class="text-caption text-grey-6"
            >
                {{ post.commentsCount - post.comments.length }} more
                {{ post.commentsCount - post.comments.length === 1 ? 'comment' : 'comments' }}
            </div>
        </div>

        <ReportDialog
            v-if="canReport"
            v-model="reporting"
            type="post"
            :id="post.id"
            :subject-name="`this post by ${authorName}`"
        />
    </div>
</template>

<style scoped>
.wall-post {
    padding: 14px;
    border-radius: 10px;
    background: #232323;
}

.post-action {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: none;
    border: 0;
    padding: 0;
    color: #9e9e9e;
    font-size: 0.8rem;
    cursor: pointer;
}

.post-action:hover {
    color: #e0e0e0;
}

.post-action--on {
    color: #ef5350;
}
</style>
