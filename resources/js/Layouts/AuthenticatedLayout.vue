<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import '@quasar/extras/material-icons/material-icons.css';
import 'quasar/src/css/index.sass';

const navLinks = [
    { name: 'feed.index', title: 'Feed', icon: 'home', },
    { name: 'map.index', title: 'Map', icon: 'map'},
    { name: 'artists.index', title: 'Artist Zone', icon: 'music' },
];

const activeTab = computed(() => navLinks.find(link => route().current(link.name))?.name);
</script>

<template>
    <q-layout view="hHh lpR fFf">
        <!-- Header -->
        <q-header elevated class="bg-dark">
            <q-toolbar>
                <q-toolbar-title class="text-h5 text-weight-bold">
                    <div class="row items-center no-wrap">
                        <Link href="/feed" class="riff-logo mr-5">
                            <q-icon name="music_note" size="28px" class="q-mr-xs" color="primary" />
                            <span class="text-primary">Riff</span>
                        </Link>
                        <div class="gt-xs flex gap-5">
                            <Link v-for="link in navLinks" :key="link.name" :href="route(link.name)" class="text-base">
                                {{ link.title }}
                            </Link>
                        </div>
                    </div>
                </q-toolbar-title>

                <q-btn flat round dense icon="search" class="q-mr-xs" color="grey-4" />
                <q-btn flat round dense icon="notifications" color="grey-4">
                    <q-badge color="primary" floating rounded>3</q-badge>
                </q-btn>
                <!-- <q-btn flat round dense icon="person" color="grey-4" /> -->
                 <q-btn flat round dense icon="person" color="grey-4">
                    <q-menu anchor="bottom right" self="top right">
                        <q-list style="min-width: 200px">
                            <q-item
                                clickable
                                v-close-popup
                                @click=""
                            >
                                <q-item-section avatar>
                                    <q-icon name="person" />
                                </q-item-section>
                                <q-item-section>Profile</q-item-section>
                            </q-item>
                            <q-item
                                clickable
                                v-close-popup
                                @click=""
                            >
                                <q-item-section avatar>
                                    <q-icon name="settings" />
                                </q-item-section>
                                <q-item-section>Settings</q-item-section>
                            </q-item>
                            <q-separator />
                            <q-item
                                clickable
                                v-close-popup
                                @click="router.post(route('logout'))"
                            >
                                <q-item-section avatar>
                                    <q-icon name="logout" />
                                </q-item-section>
                                <q-item-section>Logout</q-item-section>
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

        <!-- Bottom Navigation (Mobile) -->
        <q-footer class="bg-dark q-py-xs lt-sm" bordered>
            <q-tabs
                v-model="activeTab"
                dense
                active-color="primary"
                indicator-color="transparent"
                class="text-grey-5"
            >
                <q-route-tab v-for="link in navLinks" :key="link.name" :name="link.name" :icon="link.icon" @click="router.visit(route(link.name))" />
                <q-route-tab name="notifications" icon="notifications" />
                <q-route-tab name="profile" icon="person" />
            </q-tabs>
        </q-footer>
    </q-layout>
</template>
