<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
// Quasar and its icon font, inside the "quasar" cascade layer (see the
// layer order in resources/css/app.css).
import '../../css/icons.css';
import '../../css/quasar.sass';

// The map is public, so this layout also renders for guests: everything
// that needs an account is hidden rather than shown and then rejected.
const user = computed(() => usePage().props.auth?.user ?? null);

const navLinks = computed(() => user.value
    ? [
        { name: 'map.index', title: 'Map', icon: 'map' },
        { name: 'feed.index', title: 'Feed', icon: 'dynamic_feed' },
        { name: 'artists.*', route: 'artists.index', title: 'Artist Zone', short: 'Artists', icon: 'library_music' },
    ]
    : [{ name: 'map.index', title: 'Map', icon: 'map' }]);

// Moderators reach the report queue from the account menu rather than the
// main nav: it is not part of using Riff, and most accounts never see it.
const isModerator = computed(() => Boolean(user.value?.is_moderator));

// The phone footer adds the account page, which the header reaches
// through its menu instead.
const footerLinks = computed(() => [
    ...navLinks.value,
    { name: 'profile.*', route: 'profile.edit', title: 'Profile', icon: 'person' },
]);

const isCurrent = (link) => route().current(link.name);
const hrefFor = (link) => route(link.route ?? link.name);

const activeTab = computed(() => footerLinks.value.find(isCurrent)?.name);
</script>

<template>
    <q-layout view="hHh lpR fFf">
        <!-- Header -->
        <q-header elevated class="bg-dark">
            <q-toolbar class="gap-2">
                <Link :href="route('map.index')" class="riff-logo flex shrink-0 items-center" aria-label="Riff home">
                    <q-icon name="music_note" size="28px" color="primary" />
                    <span class="text-xl font-bold text-riff-primary">Riff</span>
                </Link>

                <nav class="riff-nav gt-xs ml-4 flex items-center gap-1" aria-label="Main">
                    <Link
                        v-for="link in navLinks"
                        :key="link.name"
                        :href="hrefFor(link)"
                        class="riff-nav__link"
                        :class="{ 'riff-nav__link--active': isCurrent(link) }"
                        :aria-current="isCurrent(link) ? 'page' : undefined"
                    >
                        {{ link.title }}
                    </Link>
                </nav>

                <q-space />

                <template v-if="!user">
                    <q-btn flat no-caps dense padding="xs md" color="grey-4" label="Sign in" :href="route('login')" type="a" />
                    <q-btn unelevated rounded no-caps dense padding="xs md" color="primary" label="Join Riff" :href="route('register')" type="a" />
                </template>

                <q-btn v-else flat round dense icon="account_circle" color="grey-4" aria-label="Account">
                    <q-menu dark anchor="bottom right" self="top right" class="bg-riff-surface-2">
                        <q-list dark style="min-width: 220px">
                            <q-item-label header class="text-grey-5">
                                <div class="truncate text-sm font-semibold text-riff-text">{{ user.name }}</div>
                                <div class="truncate text-xs">{{ user.email }}</div>
                            </q-item-label>
                            <q-separator dark />
                            <q-item v-close-popup clickable @click="router.visit(route('profile.edit'))">
                                <q-item-section avatar><q-icon name="person" /></q-item-section>
                                <q-item-section>Profile &amp; settings</q-item-section>
                            </q-item>
                            <q-item v-if="isModerator" v-close-popup clickable @click="router.visit(route('moderation.index'))">
                                <q-item-section avatar><q-icon name="flag" /></q-item-section>
                                <q-item-section>Reports</q-item-section>
                            </q-item>
                            <q-item v-close-popup clickable @click="router.post(route('logout'))">
                                <q-item-section avatar><q-icon name="logout" /></q-item-section>
                                <q-item-section>Log out</q-item-section>
                            </q-item>
                        </q-list>
                    </q-menu>
                </q-btn>
            </q-toolbar>
        </q-header>

        <!-- Main Content -->
        <q-page-container>
            <slot />
        </q-page-container>

        <!-- Bottom navigation on phones -->
        <q-footer v-if="user" class="bg-dark lt-sm" bordered>
            <q-tabs
                :model-value="activeTab"
                dense
                no-caps
                active-color="primary"
                indicator-color="primary"
                narrow-indicator
                class="riff-tabs text-grey-5"
            >
                <q-tab
                    v-for="link in footerLinks"
                    :key="link.name"
                    :name="link.name"
                    :icon="link.icon"
                    :label="link.short ?? link.title"
                    @click="router.visit(hrefFor(link))"
                />
            </q-tabs>
        </q-footer>
    </q-layout>
</template>

<style scoped>
.riff-nav__link {
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 0.95rem;
    font-weight: 600;
    color: #d4d4d4;
    transition: background 120ms ease, color 120ms ease;
}

.riff-nav__link:hover {
    background: rgb(255 255 255 / 0.06);
    color: #fff;
}

.riff-nav__link:focus-visible {
    outline: 2px solid #fff;
    outline-offset: 1px;
}

.riff-nav__link--active {
    color: #fff;
    background: rgb(255 255 255 / 0.08);
}

/* Four tabs share the width evenly; Quasar's default minimum width made
   five overflow a phone and sprout scroll arrows. */
.riff-tabs :deep(.q-tab) {
    flex: 1 1 0;
    min-width: 0;
    padding: 0 4px;
}

.riff-tabs :deep(.q-tab__label) {
    font-size: 0.7rem;
}

@media (prefers-reduced-motion: reduce) {
    .riff-nav__link {
        transition: none;
    }
}
</style>
