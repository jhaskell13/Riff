<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    posts: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    content: '',
});

const submit = () => {
    form.post(route('feed.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const authorName = (post) => post.artist?.name ?? post.user?.name ?? 'Unknown';

const initials = (post) => authorName(post)
    .split(' ')
    .map((word) => word[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();

const timeAgo = (iso) => {
    const seconds = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
    const units = [
        [31536000, 'y'],
        [2592000, 'mo'],
        [604800, 'w'],
        [86400, 'd'],
        [3600, 'h'],
        [60, 'm'],
    ];
    for (const [span, label] of units) {
        if (seconds >= span) {
            return `${Math.floor(seconds / span)}${label} ago`;
        }
    }
    return 'Just now';
};
</script>

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
                                    v-model="form.content"
                                    placeholder="Share what's happening in your music world..."
                                    dense
                                    class="col"
                                    bg-color="grey-9"
                                    color="grey-3"
                                    autogrow
                                    input-class="q-px-sm"
                                    :error="!!form.errors.content"
                                    :error-message="form.errors.content"
                                    @keyup.ctrl.enter="submit"
                                />
                            </div>
                        </q-card-section>
                        <q-card-actions align="right" class="q-pt-none q-px-md q-pb-md">
                            <q-space />
                            <q-btn
                                unelevated
                                rounded
                                color="primary"
                                label="Post"
                                padding="xs md"
                                :disable="!form.content.trim()"
                                :loading="form.processing"
                                @click="submit"
                            />
                        </q-card-actions>
                    </q-card>

                    <!-- Posts Feed -->
                    <q-card
                        v-for="post in posts.data"
                        :key="post.id"
                        class="post-card q-mb-md"
                    >
                        <q-card-section class="q-pb-sm">
                            <div class="row items-center no-wrap">
                                <q-avatar size="48px" class="avatar-gradient">
                                    <img v-if="post.artist?.image_url" :src="post.artist.image_url" />
                                    <span v-else class="text-subtitle1">{{ initials(post) }}</span>
                                </q-avatar>
                                <div class="col q-ml-md">
                                    <div class="text-subtitle1 text-weight-medium text-grey-2">
                                        {{ authorName(post) }}
                                    </div>
                                    <div class="text-caption text-grey-6">
                                        {{ timeAgo(post.published_at) }}
                                    </div>
                                </div>
                            </div>
                        </q-card-section>

                        <q-card-section class="q-pt-sm q-pb-md">
                            <div class="text-body1 text-grey-3 whitespace-pre-line">{{ post.content }}</div>
                        </q-card-section>
                    </q-card>

                    <!-- Empty state -->
                    <q-card v-if="posts.data.length === 0" class="post-card q-mb-md">
                        <q-card-section class="text-center text-grey-5 q-py-xl">
                            No posts yet. Be the first to share something!
                        </q-card-section>
                    </q-card>

                    <!-- Pagination -->
                    <div v-if="posts.prev_page_url || posts.next_page_url" class="row justify-between q-mb-xl">
                        <Link
                            v-if="posts.prev_page_url"
                            :href="posts.prev_page_url"
                            class="text-primary text-caption"
                            preserve-scroll
                        >
                            &larr; Newer posts
                        </Link>
                        <q-space />
                        <Link
                            v-if="posts.next_page_url"
                            :href="posts.next_page_url"
                            class="text-primary text-caption"
                            preserve-scroll
                        >
                            Older posts &rarr;
                        </Link>
                    </div>

                </div>
            </div>
        </q-page>
    </AuthenticatedLayout>
</template>

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

.avatar-gradient {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>
