<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import NavLink from '@/Components/NavLink.vue';

const navLinks = ref([
    {
        route: 'dashboard',
        name: 'Home Feed',
    },
    {
        route: 'dashboard',
        name: 'Live Streams',
    },
    {
        route: 'dashboard',
        name: 'Local Scene',
    },
])
</script>

<template>
    <div class=" bg-[#171E37]">
        <div class="max-w-7xl min-h-screen flex mx-auto px-0 [@media(min-width:950px)]:px-8">
            
            <!-- Navigation -->
            <nav class="hidden [@media(min-width:950px)]:flex min-w-[200px] p-8 flex-col items-start text-gray-200 bg-[#232A44]">
                <Link :href="route('dashboard')">
                    <div class="flex items-end">
                        <ApplicationLogo class="h-[50px]" />
                        <span class="ml-2 text-2xl font-bold">Riff</span>
                    </div>
                </Link>
                <NavLink
                    class="my-2"
                    v-for="link in navLinks"
                    :key="link.route"
                    :href="route(link.route)"
                    :active="route().current(link.route)"
                >
                    {{ link.name }}
                </NavLink>
            </nav>

            <!-- Main Content -->
            <main class="flex-1">
                <slot />
            </main>
        </div>
    </div>
</template>