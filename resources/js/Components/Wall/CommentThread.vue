<script setup>
import { useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ReportDialog from '@/Components/ReportDialog.vue';

// One comment and its replies. Replies are one level deep, so this renders
// a comment and a flat, *indented* list under it — never recursively.
const props = defineProps({
    comment: { type: Object, required: true },
    post: { type: Object, required: true },
    artist: { type: Object, required: true },
    canReply: { type: Boolean, default: false },
    canReplyAsArtist: { type: Boolean, default: false },
    canReport: { type: Boolean, default: false },
    maxLength: { type: Number, default: 2000 },
    // A reply itself: no further nesting, and no reply box of its own.
    isReply: { type: Boolean, default: false },
    // Where a reply posts to. A wall post and a show thread take replies at
    // different routes; everything else about them is identical, which is
    // the whole point of the polymorphic comments.
    replyRoute: { type: String, default: 'wall.comments.store' },
});

const replying = ref(false);
const reporting = ref(false);
const hiding = ref(false);
const showingTrail = ref(false);
const hideNote = ref('');

// Replies fetched on demand by "see all replies", appended to the few that
// came with the page.
const extraReplies = ref([]);
// Starts at 1 for the same reason as the comment list: the page size here
// is not the handful the wall shipped, so the ids are what deduplicate.
const repliesPage = ref(1);
const loadingReplies = ref(false);
const allRepliesLoaded = ref(false);

const replies = computed(() => [...(props.comment.replies ?? []), ...extraReplies.value]);

// Only a fan's own comment names a person to bar; an artist-voice reply is
// the band, and a member can't be shut out of their own page.
const canBan = computed(() => props.comment.canModerate
    && !props.comment.isArtist
    && Boolean(props.comment.user?.id)
    && !props.comment.canDelete);

const hiddenReplyCount = computed(() => Math.max(
    0,
    (props.comment.repliesCount ?? 0) - replies.value.length,
));

const loadReplies = async () => {
    if (loadingReplies.value) {
        return;
    }

    loadingReplies.value = true;

    try {
        const { data } = await window.axios.get(
            route('wall.replies.index', props.comment.id),
            { params: { page: repliesPage.value } },
        );

        // The first page already came with the wall, so skip anything we
        // are already showing rather than trusting the page offset.
        const seen = new Set(replies.value.map((r) => r.id));
        extraReplies.value.push(...data.replies.filter((r) => !seen.has(r.id)));

        if (data.nextPage) {
            repliesPage.value = data.nextPage;
        } else {
            allRepliesLoaded.value = true;
        }
    } finally {
        loadingReplies.value = false;
    }
};

const replyForm = useForm({ content: '', parent_id: props.comment.id, as_artist: false });

const submitReply = (asArtist) => {
    if (!replyForm.content.trim()) {
        return;
    }

    replyForm.as_artist = asArtist;
    replyForm.post(route(props.replyRoute, props.post.id), {
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
    if (confirm('Delete this comment? This cannot be undone.')) {
        router.delete(route('wall.comments.destroy', comment.id), { preserveScroll: true });
    }
};

// Hiding is the artist's tool and is always reversible — it takes the
// comment off the public wall and leaves it readable here.
const hide = () => {
    router.post(
        route('wall.comments.hide', props.comment.id),
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
    router.delete(route('wall.comments.unhide', props.comment.id), { preserveScroll: true });
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
        { user_id: props.comment.user?.id, reason: banReason.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                banning.value = false;
                banReason.value = '';
            },
        },
    );
};

// Badge colour by what the badge *means*, not by who said it: the band
// itself, another act on the same bill, or a person who is in one of them.
const BADGE_TONES = {
    owner: 'primary',
    lineup: 'deep-purple-4',
    member: 'blue-grey-6',
};

const badgeColor = (badge) => BADGE_TONES[badge?.tone] ?? 'grey-7';

const authorName = (comment) => (comment.isArtist
    ? (comment.artist?.name ?? 'The artist')
    : (comment.user?.name ?? 'Someone'));
</script>

<template>
    <div :id="`comment-${comment.id}`" class="comment" :class="{ 'comment--hidden': comment.isHidden }">
        <div class="row items-start no-wrap gap-2">
            <q-avatar
                :size="isReply ? '22px' : '26px'"
                :color="comment.isArtist ? 'primary' : 'grey-8'"
                text-color="white"
            >
                <img v-if="comment.isArtist && comment.artist?.image_url" :src="comment.artist.image_url" alt="" />
                <q-icon v-else :name="comment.isArtist ? 'groups' : 'person'" :size="isReply ? '13px' : '15px'" />
            </q-avatar>
            <div class="col">
                <div class="row items-center gap-2">
                    <span class="text-body2 text-weight-medium text-grey-3">{{ authorName(comment) }}</span>
                    <!-- The author's standing in this thread: "Artist",
                         "Host", "On the bill", "Member of X". Never a repeat
                         of the name on the line above it. Computed server
                         side from stored state, so it can be trusted. -->
                    <q-badge v-if="comment.badge" :color="badgeColor(comment.badge)" class="text-caption">
                        {{ comment.badge.label }}
                    </q-badge>
                    <q-badge v-if="comment.isHidden" color="grey-8" text-color="grey-4" class="text-caption">
                        <q-icon name="visibility_off" size="11px" class="q-mr-xs" /> Hidden
                    </q-badge>
                </div>

                <!-- Only members ever see a hidden row, so this trail is
                     only ever rendered to them. -->
                <div v-if="comment.isHidden && comment.moderation" class="text-caption text-grey-6">
                    {{ comment.moderation.label }}
                    <span v-if="comment.moderation.by">by {{ comment.moderation.by }}</span>
                    <span v-if="comment.moderation.note">— “{{ comment.moderation.note }}”</span>
                </div>

                <!-- The history, not just the current state: a row hidden,
                     restored and hidden again should read as that. Members
                     only — nobody else is ever sent a hidden row. -->
                <div v-if="comment.moderationTrail?.length > 1" class="q-mt-xs">
                    <button class="comment-action" @click="showingTrail = !showingTrail">
                        <q-icon :name="showingTrail ? 'expand_less' : 'history'" size="13px" />
                        {{ showingTrail ? 'Hide history' : `${comment.moderationTrail.length} moderation actions` }}
                    </button>
                    <ul v-if="showingTrail" class="trail">
                        <li v-for="event in comment.moderationTrail" :key="event.id" class="text-caption text-grey-6">
                            {{ event.label }}<span v-if="event.by"> by {{ event.by }}</span>
                            <span v-if="event.at"> · {{ new Date(event.at).toLocaleString() }}</span>
                            <span v-if="event.note"> — “{{ event.note }}”</span>
                        </li>
                    </ul>
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
                    <button v-if="canReply && !isReply" class="comment-action" @click="replying = !replying">
                        Reply
                    </button>
                    <button v-if="comment.canDelete" class="comment-action" @click="remove(comment)">
                        Delete
                    </button>
                    <!-- A member's tools: hide (reversible) and report
                         (asks a moderator). Never delete someone else's. -->
                    <button
                        v-if="comment.canModerate && !comment.isHidden && !comment.canDelete"
                        class="comment-action"
                        @click="hiding = !hiding"
                    >
                        Hide
                    </button>
                    <button
                        v-if="comment.canModerate && comment.isHidden"
                        class="comment-action comment-action--undo"
                        @click="unhide"
                    >
                        Unhide
                    </button>
                    <button
                        v-if="canBan"
                        class="comment-action"
                        @click="banning = !banning"
                    >
                        Remove from wall
                    </button>
                    <button
                        v-if="canReport && !comment.canDelete"
                        class="comment-action"
                        @click="reporting = true"
                    >
                        Report
                    </button>
                </div>

                <div v-if="hiding" class="column gap-2 q-mt-xs hide-box">
                    <p class="text-caption text-grey-5 m-0">
                        Hiding takes this off your public wall. Nothing is deleted, you can undo it, and
                        it stays in your wall log. To have it removed from Riff, report it instead.
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

                <div v-if="banning" class="column gap-2 q-mt-xs hide-box">
                    <p class="text-caption text-grey-5 m-0">
                        {{ authorName(comment) }} will not be able to post on your wall. They keep following you,
                        keep reading, and anything they already wrote stays up — hide that separately if you
                        want it gone. You can allow them back at any time.
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

                <!-- Replies: one level, flat, and clearly indented. -->
                <div v-if="replies.length" class="column gap-3 q-mt-sm reply-list">
                    <CommentThread
                        v-for="reply in replies"
                        :key="reply.id"
                        :comment="reply"
                        :post="post"
                        :artist="artist"
                        :can-reply="false"
                        :can-report="canReport"
                        :reply-route="replyRoute"
                        is-reply
                    />
                </div>

                <div v-if="hiddenReplyCount > 0 && !allRepliesLoaded" class="q-mt-xs reply-more">
                    <button class="comment-action comment-action--more" :disabled="loadingReplies" @click="loadReplies">
                        <q-icon name="subdirectory_arrow_right" size="14px" />
                        {{ loadingReplies ? 'Loading…' : `See ${hiddenReplyCount} more ${hiddenReplyCount === 1 ? 'reply' : 'replies'}` }}
                    </button>
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

/* Only a member ever sees one of these; it has to look different enough
   that they never mistake it for live content on their own page. */
.comment--hidden {
    opacity: 0.62;
    border-left: 2px solid #5d4037;
    padding-left: 8px;
}

/* Replies are indented and rail-marked: aligned with the parent they were
   indistinguishable from the comments beside them. */
.reply-list {
    margin-left: 4px;
    padding-left: 16px;
    border-left: 2px solid #3a3a3a;
}

.reply-more {
    margin-left: 4px;
    padding-left: 16px;
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

.comment-action:disabled {
    cursor: default;
    opacity: 0.6;
}

.comment-action--on {
    color: #ef5350;
}

.comment-action--undo {
    color: #ffb74d;
}

.comment-action--more {
    color: #90caf9;
}

.trail {
    margin: 4px 0 0;
    padding-left: 16px;
    list-style: disc;
}

.hide-box {
    padding: 8px 10px;
    border: 1px solid #3a3a3a;
    border-radius: 8px;
    background: #1c1c1c;
}
</style>
