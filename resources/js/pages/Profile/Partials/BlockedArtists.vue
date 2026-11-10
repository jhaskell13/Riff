<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

/** The only place a block can be undone. */
defineProps({
    artists: { type: Array, default: () => [] },
});

const pendingId = ref(null);

const unblock = (artist) => {
    pendingId.value = artist.id;

    router.delete(route('artists.unblock', artist.id), {
        preserveScroll: true,
        onFinish: () => (pendingId.value = null),
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Blocked artists
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Artists you've blocked are hidden from your map and your feed. They
                aren't told, and they still appear for everyone else.
            </p>
        </header>

        <p v-if="artists.length === 0" class="mt-6 text-sm text-gray-600 dark:text-gray-400">
            You haven't blocked anyone.
        </p>

        <ul v-else class="mt-6 divide-y divide-gray-200 dark:divide-gray-700">
            <li
                v-for="artist in artists"
                :key="artist.id"
                class="flex items-center justify-between gap-4 py-3"
            >
                <div class="min-w-0">
                    <div class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ artist.name }}
                    </div>
                    <div class="truncate text-xs text-gray-600 dark:text-gray-400">
                        {{ artist.location }}
                    </div>
                </div>

                <button
                    type="button"
                    class="shrink-0 rounded-md border border-gray-300 px-3 py-1 text-sm text-gray-700 hover:border-indigo-500 focus:ring-2 focus:ring-indigo-500 focus:outline-hidden disabled:opacity-50 dark:border-gray-600 dark:text-gray-300"
                    :disabled="pendingId === artist.id"
                    @click="unblock(artist)"
                >
                    Unblock
                </button>
            </li>
        </ul>
    </section>
</template>
