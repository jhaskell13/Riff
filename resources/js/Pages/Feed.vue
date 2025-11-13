<template>
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">

                    <!-- New Post Card -->
                    <q-card class="post-card q-mb-md">
                        <q-card-section class="q-pb-md">
                            <div class="row items-center q-gutter-md">
                                <q-avatar size="44px" color="primary" text-color="white" class="q-mt-md">
                                    <q-icon name="person" size="24px" />
                                </q-avatar>
                                <q-input
                                    v-model="newPost"
                                    placeholder="Share what's happening in your music world..."
                                    dense
                                    class="col"
                                    bg-color="grey-9"
                                    color="grey-3"
                                    autogrow
                                    input-class="q-px-sm"
                                />
                            </div>
                        </q-card-section>
                        <q-card-actions align="right" class="q-pt-none q-px-md q-pb-md">
                            <q-btn
                                flat
                                dense
                                icon="image"
                                color="grey-5"
                                size="md"
                                class="q-mr-xs"
                            >
                                <q-tooltip>Add Photo</q-tooltip>
                            </q-btn>
                            <q-btn
                                flat
                                dense
                                icon="audiotrack"
                                color="grey-5"
                                size="md"
                            >
                                <q-tooltip>Add Audio</q-tooltip>
                            </q-btn>
                            <q-space />
                            <q-btn
                                unelevated
                                rounded
                                color="primary"
                                label="Post"
                                padding="xs md"
                                :disable="!newPost.trim()"
                                @click="addPost"
                            />
                        </q-card-actions>
                    </q-card>

                    <!-- Posts Feed -->
                    <q-card
                        v-for="post in posts"
                        :key="post.id"
                        class="post-card q-mb-md"
                    >
                        <!-- Post Header -->
                        <q-card-section class="q-pb-sm">
                        <div class="row items-center no-wrap">
                            <q-avatar size="48px" class="avatar-gradient">
                            <span class="text-h6">{{ post.avatar }}</span>
                            </q-avatar>
                            <div class="col q-ml-md">
                            <div class="text-subtitle1 text-weight-medium text-grey-2">{{ post.author }}</div>
                            <div class="text-caption text-grey-6">{{ post.username }} • {{ post.timestamp }}</div>
                            </div>
                            <q-btn flat round dense icon="more_vert" color="grey-5" size="sm" />
                        </div>
                        </q-card-section>

                        <!-- Post Content -->
                        <q-card-section class="q-pt-sm q-pb-md">
                        <div class="text-body1 text-grey-3">{{ post.content }}</div>
                        </q-card-section>

                        <!-- Post Image (if exists) -->
                        <q-img
                        v-if="post.image"
                        :src="post.image"
                        ratio="16/9"
                        class="rounded-borders"
                        />

                        <q-separator color="grey-8" />

                        <!-- Post Actions -->
                        <q-card-actions align="around" class="q-py-sm">
                        <q-btn
                            flat
                            dense
                            :color="post.liked ? 'primary' : 'grey-5'"
                            icon="favorite"
                            :label="$q.screen.gt.xs ? post.likes : ''"
                            class="action-btn"
                            @click="toggleLike(post.id)"
                        />
                        <q-btn
                            flat
                            dense
                            color="grey-5"
                            icon="chat_bubble_outline"
                            :label="$q.screen.gt.xs ? post.comments : ''"
                            class="action-btn"
                            @click="toggleComments(post.id)"
                        />
                        <q-btn
                            flat
                            dense
                            color="grey-5"
                            icon="share"
                            :label="$q.screen.gt.xs ? post.shares : ''"
                            class="action-btn"
                        />
                        </q-card-actions>

                        <!-- Comments Section -->
                        <div v-if="post.showComments">
                        <q-separator color="grey-8" />
                        <q-card-section class="comment-section">
                            <div
                            v-for="comment in post.commentsList"
                            :key="comment.id"
                            class="q-mb-md"
                            >
                            <div class="row items-start q-gutter-sm">
                                <q-avatar size="36px" class="avatar-gradient-sm">
                                <span class="text-body2">{{ comment.avatar }}</span>
                                </q-avatar>
                                <div class="col">
                                <div class="comment-bubble">
                                    <div class="text-weight-medium text-body2 text-grey-2">{{ comment.author }}</div>
                                    <div class="text-body2 text-grey-3">{{ comment.text }}</div>
                                </div>
                                <div class="text-caption text-grey-6 q-mt-xs q-ml-sm">
                                    {{ comment.timestamp }}
                                </div>
                                </div>
                            </div>
                            </div>

                            <!-- Add Comment -->
                            <div class="row items-center q-gutter-sm q-mt-md">
                            <q-avatar size="36px" color="primary" text-color="white">
                                <q-icon name="person" size="20px" />
                            </q-avatar>
                            <q-input
                                v-model="post.newComment"
                                placeholder="Write a comment..."
                                outlined
                                dense
                                rounded
                                bg-color="grey-9"
                                color="grey-3"
                                class="col"
                                @keyup.enter="addComment(post.id)"
                            >
                                <template v-slot:append>
                                <q-btn
                                    flat
                                    dense
                                    round
                                    icon="send"
                                    color="primary"
                                    size="sm"
                                    :disable="!post.newComment?.trim()"
                                    @click="addComment(post.id)"
                                />
                                </template>
                            </q-input>
                            </div>
                        </q-card-section>
                        </div>
                    </q-card>

                </div>
            </div>
        </q-page>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const newPost = ref('');
const posts = ref([
    {
    id: 1,
    author: 'The Midnight Strummers',
    username: '@midnightstrummers',
    avatar: '🎸',
    content: 'Just wrapped up our set at The Blue Moon! Thanks to everyone who came out tonight. New single dropping next week 🔥',
    image: null,
    likes: 234,
    comments: 18,
    shares: 12,
    timestamp: '2h ago',
    liked: false,
    showComments: false,
    newComment: '',
    commentsList: [
        {
        id: 1,
        author: 'Music Fan',
        avatar: '😎',
        text: 'Amazing show! Can\'t wait for the new single!',
        timestamp: '1h ago'
        },
        {
        id: 2,
        author: 'Local Venue',
        avatar: '🎪',
        text: 'Thanks for playing! You guys rocked the house!',
        timestamp: '2h ago'
        }
    ]
    },
    {
    id: 2,
    author: 'Echo Valley Band',
    username: '@echovalley',
    avatar: '🎵',
    content: 'Recording our debut album this weekend! Been working on these tracks for months. Can\'t wait to share them with you all.',
    image: null,
    likes: 189,
    comments: 24,
    shares: 8,
    timestamp: '5h ago',
    liked: false,
    showComments: false,
    newComment: '',
    commentsList: []
    },
    {
    id: 3,
    author: 'Luna & The Howlers',
    username: '@lunahowlers',
    avatar: '🎤',
    content: 'Open mic night at Java Junction this Thursday! Come support local talent. Sign up starts at 7pm.',
    image: null,
    likes: 156,
    comments: 31,
    shares: 45,
    timestamp: '8h ago',
    liked: true,
    showComments: false,
    newComment: '',
    commentsList: []
    }
]);

const addPost = () => {
    if (newPost.value.trim()) {
    posts.value.unshift({
        id: Date.now(),
        author: 'Your Band',
        username: '@yourband',
        avatar: '🎹',
        content: newPost.value,
        image: null,
        likes: 0,
        comments: 0,
        shares: 0,
        timestamp: 'Just now',
        liked: false,
        showComments: false,
        newComment: '',
        commentsList: []
    });
    newPost.value = '';
    }
};

const toggleLike = (postId) => {
    const post = posts.value.find(p => p.id === postId);
    if (post) {
    post.liked = !post.liked;
    post.likes += post.liked ? 1 : -1;
    }
};

const toggleComments = (postId) => {
    const post = posts.value.find(p => p.id === postId);
    if (post) {
    post.showComments = !post.showComments;
    }
};

const addComment = (postId) => {
    const post = posts.value.find(p => p.id === postId);
    if (post && post.newComment?.trim()) {
    post.commentsList.push({
        id: Date.now(),
        author: 'You',
        avatar: '👤',
        text: post.newComment,
        timestamp: 'Just now'
    });
    post.comments++;
    post.newComment = '';
    }
};
</script>

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

.riff-logo {
  display: flex;
  align-items: center;
  font-size: 24px;
  letter-spacing: 1px;
}

.avatar-gradient {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  display: flex;
  align-items: center;
  justify-content: center;
}

.avatar-gradient-sm {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  display: flex;
  align-items: center;
  justify-content: center;
}

.action-btn {
  text-transform: none;
  font-weight: 500;
  transition: all 0.2s ease;
}

.action-btn:hover {
  background-color: rgba(103, 126, 234, 0.1);
}

.comment-section {
  background: #181818;
  padding: 16px;
}

.comment-bubble {
  background: #2a2a2a;
  border-radius: 12px;
  padding: 10px 14px;
}

.hover-text:hover {
  color: #667eea;
  transition: color 0.2s ease;
}

:deep(.q-field__control) {
  border-radius: 8px;
}

:deep(.q-field__native) {
  color: #e0e0e0;
}

:deep(.q-placeholder) {
  color: #757575;
}
</style>
