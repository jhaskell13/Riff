<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// The bell: a badge in the header, and a short dropdown of the most recent
// few with a way through to everything.
//
// The list is fetched when the menu opens rather than shared with every
// page — a count is cheap to put on every response, a list of titles and
// bodies is not. The dropdown is deliberately short and ends in "See all":
// a scrolling panel of notifications is a feed in a smaller box, and this
// product does not have one.
const unread = computed(() => usePage().props.notifications?.unread ?? 0);

const open = ref(false);
const loading = ref(false);
const loaded = ref(false);
const items = ref([]);

const load = async () => {
    loading.value = true;

    try {
        const { data } = await window.axios.get(route('notifications.recent'));
        items.value = data.notifications;
        loaded.value = true;
    } finally {
        loading.value = false;
    }
};

// Re-fetched on each open: something may have arrived since last time, and
// a stale dropdown is worse than a brief spinner.
const onShow = () => load();

const when = (iso) => (iso
    ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
    : '');

const go = (notification) => {
    open.value = false;

    const visit = () => {
        if (notification.url) {
            router.visit(notification.url);
        }
    };

    if (notification.readAt) {
        visit();

        return;
    }

    router.post(route('notifications.read', notification.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: visit,
    });
};

const markAllRead = () => {
    router.post(route('notifications.readAll'), {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => load(),
    });
};
</script>

<template>
    <q-btn
        flat round dense
        icon="notifications"
        color="grey-4"
        :aria-label="unread ? `Notifications (${unread} unread)` : 'Notifications'"
    >
        <q-badge v-if="unread" color="primary" floating rounded>
            {{ unread > 99 ? '99+' : unread }}
        </q-badge>

        <q-menu
            v-model="open"
            dark
            anchor="bottom right"
            self="top right"
            class="bg-riff-surface-2"
            @show="onShow"
        >
            <div class="bell" style="min-width: 320px; max-width: 380px">
                <div class="row items-center q-px-md q-pt-sm">
                    <span class="text-caption text-grey-5">Notifications</span>
                    <q-space />
                    <q-btn
                        v-if="unread"
                        flat dense no-caps rounded size="sm"
                        color="primary"
                        label="Mark all read"
                        @click.stop="markAllRead"
                    />
                </div>

                <q-separator color="grey-9" class="q-mt-sm" />

                <div v-if="loading && !loaded" class="q-pa-md row justify-center">
                    <q-spinner color="grey-6" size="22px" />
                </div>

                <q-list v-else-if="items.length" dark>
                    <q-item
                        v-for="notification in items"
                        :key="notification.id"
                        v-close-popup
                        clickable
                        :class="{ 'bell__item--unread': !notification.readAt }"
                        @click="go(notification)"
                    >
                        <q-item-section avatar>
                            <q-icon
                                :name="notification.icon"
                                size="20px"
                                :color="notification.readAt ? 'grey-6' : 'primary'"
                            />
                        </q-item-section>
                        <q-item-section>
                            <q-item-label class="text-body2 text-grey-2">
                                {{ notification.title }}
                                <span v-if="notification.count > 1" class="text-caption text-grey-5">
                                    and {{ notification.count - 1 }} more
                                </span>
                            </q-item-label>
                            <q-item-label v-if="notification.body" caption lines="2" class="text-grey-5">
                                {{ notification.body }}
                            </q-item-label>
                            <q-item-label caption class="text-grey-7">{{ when(notification.createdAt) }}</q-item-label>
                        </q-item-section>
                    </q-item>
                </q-list>

                <div v-else class="q-pa-md text-caption text-grey-6">
                    Nothing yet. Follow an artist and you'll hear when they post or list a show.
                </div>

                <q-separator color="grey-9" />

                <q-item v-close-popup clickable class="text-center" @click="router.visit(route('notifications.index'))">
                    <q-item-section>
                        <q-item-label class="text-body2 text-primary">See all and settings</q-item-label>
                    </q-item-section>
                </q-item>
            </div>
        </q-menu>
    </q-btn>
</template>

<style scoped>
/* A rail rather than a colour wash, so the text stays as readable as the
   rest of the menu. */
.bell__item--unread {
    border-left: 3px solid var(--q-primary, #1976d2);
}
</style>
