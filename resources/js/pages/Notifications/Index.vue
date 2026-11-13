<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

// What Riff has told you, and what you want to be told.
//
// A page rather than a dropdown on purpose: a scrolling panel of
// notifications is a feed in a smaller box, and this product does not have
// one. Everything here is anchored — each line goes to an artist, a thread
// or a show.
const props = defineProps({
    notifications: { type: Array, default: () => [] },
    nextPage: { type: Number, default: null },
    unreadCount: { type: Number, default: 0 },
    preferences: { type: Array, default: () => [] },
    digest: { type: Object, default: () => ({ frequency: 'weekly', options: [] }) },
});

const when = (iso) => (iso
    ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
    : '');

// Opening one marks it read on the way through, so the badge matches what
// you have actually looked at.
const open = (notification) => {
    if (!notification.readAt) {
        router.post(route('notifications.read', notification.id), {}, {
            preserveScroll: true,
            onFinish: () => {
                if (notification.url) {
                    router.visit(notification.url);
                }
            },
        });

        return;
    }

    if (notification.url) {
        router.visit(notification.url);
    }
};

const markAllRead = () => router.post(route('notifications.readAll'), {}, { preserveScroll: true });

const prefsForm = useForm({
    preferences: Object.fromEntries(props.preferences.map((p) => [p.value, p.enabled])),
    digest_frequency: props.digest.frequency,
});

const savePreferences = () => prefsForm.patch(route('notifications.preferences'), { preserveScroll: true });

const hasAny = computed(() => props.notifications.length > 0);
</script>

<template>
    <Head title="Notifications" />
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-mb-md">
                        <q-card-section class="row items-center">
                            <div class="text-h6 text-weight-bold text-grey-3">Notifications</div>
                            <q-space />
                            <q-btn
                                v-if="unreadCount"
                                flat dense no-caps rounded
                                color="primary"
                                :label="`Mark ${unreadCount} read`"
                                @click="markAllRead"
                            />
                        </q-card-section>

                        <q-card-section v-if="hasAny" class="q-pt-none column gap-2">
                            <button
                                v-for="notification in notifications"
                                :key="notification.id"
                                class="notice"
                                :class="{ 'notice--unread': !notification.readAt }"
                                @click="open(notification)"
                            >
                                <q-icon
                                    :name="notification.icon"
                                    size="20px"
                                    :color="notification.readAt ? 'grey-6' : 'primary'"
                                />
                                <span class="notice__body">
                                    <span class="text-body2 text-grey-2">
                                        {{ notification.title }}
                                        <!-- How many folded into this one:
                                             five posts from one band in an
                                             afternoon is one line. -->
                                        <span v-if="notification.count > 1" class="text-caption text-grey-5">
                                            and {{ notification.count - 1 }} more
                                        </span>
                                    </span>
                                    <span v-if="notification.body" class="text-caption text-grey-5 block">
                                        {{ notification.body }}
                                    </span>
                                    <span class="text-caption text-grey-7 block">{{ when(notification.createdAt) }}</span>
                                </span>
                            </button>

                            <Link
                                v-if="nextPage"
                                :href="route('notifications.index', { page: nextPage })"
                                class="text-caption text-grey-5"
                            >
                                Older →
                            </Link>
                        </q-card-section>

                        <q-card-section v-else class="q-pt-none">
                            <p class="text-grey-6 m-0">
                                Nothing yet. Follow an artist and you'll hear when they post or list a show.
                            </p>
                        </q-card-section>
                    </q-card>

                    <q-card class="post-card q-mb-xl">
                        <q-card-section>
                            <div class="text-subtitle1 text-weight-medium text-grey-3">What you hear about</div>
                            <p class="text-caption text-grey-6 m-0 q-mt-xs">
                                You can also mute an individual artist from their page without turning a whole
                                kind off.
                            </p>
                        </q-card-section>
                        <q-card-section class="q-pt-none column gap-2">
                            <div v-for="preference in preferences" :key="preference.value" class="row items-start no-wrap gap-3">
                                <q-toggle
                                    v-model="prefsForm.preferences[preference.value]"
                                    dark
                                    color="primary"
                                    :disable="preference.required"
                                    :aria-label="preference.label"
                                />
                                <div class="col leading-tight">
                                    <div class="text-body2 text-grey-3">{{ preference.label }}</div>
                                    <div class="text-caption text-grey-6">{{ preference.description }}</div>
                                    <!-- Switching this off would quietly
                                         break other people's listings. -->
                                    <div v-if="preference.required" class="text-caption text-grey-7">
                                        Always on — an unanswered invitation keeps someone else's show unlisted.
                                    </div>
                                </div>
                            </div>
                        </q-card-section>
                        <q-separator color="grey-9" />

                        <q-card-section>
                            <div class="text-subtitle2 text-weight-medium text-grey-5">Email</div>
                            <p class="text-caption text-grey-6 m-0 q-mt-xs">
                                A weekly round-up: the shows you said you're going to, when the artists you
                                follow next play, what they've said, and what's on near you. Nothing is sent
                                in a week where there's nothing to say.
                            </p>
                            <q-select
                                v-model="prefsForm.digest_frequency"
                                :options="digest.options"
                                emit-value map-options
                                dense outlined dark
                                label="Weekly digest"
                                label-color="grey-5"
                                class="q-mt-sm"
                            />
                        </q-card-section>

                        <q-card-actions align="right" class="q-px-md q-pb-md">
                            <q-btn
                                unelevated rounded color="primary"
                                label="Save"
                                padding="xs lg"
                                :loading="prefsForm.processing"
                                @click="savePreferences"
                            />
                        </q-card-actions>
                    </q-card>
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

.notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    width: 100%;
    padding: 10px 12px;
    border: 0;
    border-radius: 8px;
    background: #232323;
    text-align: left;
    cursor: pointer;
}

.notice:hover {
    background: #2b2b2b;
}

/* Unread is marked by a rail rather than a colour wash: the text stays as
   readable as everything else on the page. */
.notice--unread {
    border-left: 3px solid var(--q-primary, #1976d2);
}

.notice__body {
    display: block;
    flex: 1;
}

.block {
    display: block;
}
</style>
