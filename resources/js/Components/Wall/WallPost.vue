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
const hiding = ref(false);
const showingTrail = ref(false);
const hideNote = ref('');

// Comments fetched on demand by "show more comments", appended to the few
// that came with the page. A wall must not become an unbounded scroll just
// because one post got busy.
const extraComments = ref([]);
// Starts at 1, not 2. The wall ships `comments_per_post` comments but this
// endpoint pages by `comments_per_page`, so "page 2" would skip everything
// between the two. Fetching from the first page and filtering by id costs
// one small overlap and cannot silently lose a comment.
const commentsPage = ref(1);
const loadingComments = ref(false);
const allCommentsLoaded = ref(false);

const comments = computed(() => [...(props.post.comments ?? []), ...extraComments.value]);

const hiddenCommentCount = computed(() => Math.max(
    0,
    (props.post.commentsCount ?? 0) - comments.value.length,
));

const loadComments = async () => {
    if (loadingComments.value) {
        return;
    }

    loadingComments.value = true;

    try {
        const { data } = await window.axios.get(
            route('wall.comments.index', props.post.id),
            { params: { page: commentsPage.value } },
        );

        // The first page came with the wall, so filter by id rather than
        // trusting the offset to line up.
        const seen = new Set(comments.value.map((c) => c.id));
        extraComments.value.push(...data.comments.filter((c) => !seen.has(c.id)));

        if (data.nextPage) {
            commentsPage.value = data.nextPage;
        } else {
            allCommentsLoaded.value = true;
        }
    } finally {
        loadingComments.value = false;
    }
};

const commentForm = useForm({ content: '', parent_id: null, as_artist: false });

// An artist-voice post never carries a user: the band said it, and which
// member typed it is not published (PostResource enforces this server-side
// — this is only the rendering of that decision).
const isArtistVoice = computed(() => props.post.authorContext === 'artist');

const authorName = computed(() => (isArtistVoice.value
    ? (props.post.artist?.name ?? props.artist.name)
    : (props.post.user?.name ?? 'Someone')));

// Badge colour by what the badge *means*, not by who said it: the band
// itself, another act on the same bill, or a person who is in one of them.
const BADGE_TONES = {
    owner: 'primary',
    lineup: 'deep-purple-4',
    member: 'blue-grey-6',
};

const badgeColor = (badge) => BADGE_TONES[badge?.tone] ?? 'grey-7';

const canBan = computed(() => props.post.canModerate
    && !isArtistVoice.value
    && Boolean(props.post.user?.id)
    && !props.post.canDelete);

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


// Barring someone from this wall. They keep following and keep reading —
// a ban is about the wall, not the fandom — and their existing posts stay
// up, because "stop posting here" and "take down what you said" are
// deliberately separate decisions.
const banning = ref(false);
const banReason = ref('');

const ban = () => {
    router.post(
        route('wall.bans.store', props.artist.id),
        { user_id: props.post.user?.id, reason: banReason.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                banning.value = false;
                banReason.value = '';
            },
        },
    );
};

const remove = () => {
    if (confirm('Delete this post? This cannot be undone.')) {
        router.delete(route('wall.posts.destroy', props.post.id), { preserveScroll: true });
    }
};

// A member's tools on their own wall: hide (always reversible) and report
// (asks a moderator). Never delete what a fan wrote.
const hide = () => {
    router.post(
        route('wall.posts.hide', props.post.id),
        { note: hideNote.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                hiding.value = false;
                hideNote.value = '';
            },
        },
    );
};

const unhide = () => {
    router.delete(route('wall.posts.unhide', props.post.id), { preserveScroll: true });
};
</script>

<template>
    <div
        :id="`post-${post.id}`"
        class="wall-post column gap-2"
        :class="{ 'wall-post--hidden': post.isHidden }"
    >
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
                    <q-badge v-if="post.badge" :color="badgeColor(post.badge)" class="text-caption">
                        {{ post.badge.label }}
                    </q-badge>
                    <q-badge v-if="post.isHidden" color="grey-8" text-color="grey-4" class="text-caption">
                        <q-icon name="visibility_off" size="11px" class="q-mr-xs" /> Hidden
                    </q-badge>
                </div>
                <div class="text-caption text-grey-6">{{ when }}</div>
                <div v-if="post.isHidden && post.moderation" class="text-caption text-grey-6">
                    {{ post.moderation.label }}
                    <span v-if="post.moderation.by">by {{ post.moderation.by }}</span>
                    <span v-if="post.moderation.note">— “{{ post.moderation.note }}”</span>
                </div>
            </div>
        </div>

        <!-- The history, not just the current state: a row hidden,
             restored and hidden again should read as that. Members
             only — nobody else is ever sent a hidden row. -->
        <div v-if="post.moderationTrail?.length > 1" class="q-mt-xs">
            <button class="post-action" @click="showingTrail = !showingTrail">
                <q-icon :name="showingTrail ? 'expand_less' : 'history'" size="13px" />
                {{ showingTrail ? 'Hide history' : `${post.moderationTrail.length} moderation actions` }}
            </button>
            <ul v-if="showingTrail" class="trail">
                <li v-for="event in post.moderationTrail" :key="event.id" class="text-caption text-grey-6">
                    {{ event.label }}<span v-if="event.by"> by {{ event.by }}</span>
                    <span v-if="event.at"> · {{ new Date(event.at).toLocaleString() }}</span>
                    <span v-if="event.note"> — “{{ event.note }}”</span>
                </li>
            </ul>
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
            <button
                v-if="post.canModerate && !post.isHidden && !post.canDelete"
                class="post-action"
                @click="hiding = !hiding"
            >
                Hide
            </button>
            <button
                v-if="post.canModerate && post.isHidden"
                class="post-action post-action--undo"
                @click="unhide"
            >
                Unhide
            </button>
            <button v-if="canBan" class="post-action" @click="banning = !banning">
                Remove from wall
            </button>
            <button v-if="canReport && !post.canDelete" class="post-action" @click="reporting = true">
                Report
            </button>
        </div>

        <div v-if="banning" class="column gap-2 hide-box">
            <p class="text-caption text-grey-5 m-0">
                {{ authorName }} will not be able to post on your wall. They keep following you, keep reading,
                and anything they already wrote stays up — hide that separately if you want it gone. You can
                allow them back at any time.
            </p>
            <q-input
                v-model="banReason"
                dense outlined dark
                label="Reason for your own log (optional)"
                label-color="grey-5"
                maxlength="500"
            />
            <div class="row gap-2">
                <q-btn unelevated rounded no-caps dense color="grey-7" label="Remove them" padding="xs md" @click="ban" />
                <q-btn flat rounded no-caps dense color="grey-6" label="Cancel" @click="banning = false" />
            </div>
        </div>

        <div v-if="hiding" class="column gap-2 hide-box">
            <p class="text-caption text-grey-5 m-0">
                Hiding takes this off your public wall. Nothing is deleted, you can undo it, and it stays
                in your wall log. To have it removed from Riff, report it instead.
            </p>
            <q-input
                v-model="hideNote"
                dense outlined dark
                label="Note for your own log (optional)"
                label-color="grey-5"
                maxlength="500"
            />
            <div class="row gap-2">
                <q-btn unelevated rounded no-caps dense color="grey-7" label="Hide it" padding="xs md" @click="hide" />
                <q-btn flat rounded no-caps dense color="grey-6" label="Cancel" @click="hiding = false" />
            </div>
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

        <div v-if="comments.length" class="column gap-3 q-mt-xs">
            <CommentThread
                v-for="comment in comments"
                :key="comment.id"
                :comment="comment"
                :post="post"
                :artist="artist"
                :can-reply="post.canComment"
                :can-reply-as-artist="canPostAsArtist"
                :can-report="canReport"
                :max-length="maxLength"
            />
            <button
                v-if="hiddenCommentCount > 0 && !allCommentsLoaded"
                class="post-action post-action--more"
                :disabled="loadingComments"
                @click="loadComments"
            >
                <q-icon name="expand_more" size="16px" />
                {{ loadingComments ? 'Loading…' : `Show ${hiddenCommentCount} more ${hiddenCommentCount === 1 ? 'comment' : 'comments'}` }}
            </button>
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

/* Only a member ever sees one of these. */
.wall-post--hidden {
    opacity: 0.68;
    border-left: 3px solid #5d4037;
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

.post-action--undo {
    color: #ffb74d;
}

.post-action--more {
    color: #90caf9;
    align-self: flex-start;
}

.post-action:disabled {
    cursor: default;
    opacity: 0.6;
}

.trail {
    margin: 4px 0 0;
    padding-left: 16px;
    list-style: disc;
}

.hide-box {
    padding: 10px 12px;
    border: 1px solid #3a3a3a;
    border-radius: 8px;
    background: #1c1c1c;
}
</style>
