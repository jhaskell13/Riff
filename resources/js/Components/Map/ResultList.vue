<script setup>
import { computed, ref, watch } from 'vue';

// Row elements, kept off the reactive graph: they are only ever read to
// scroll the selected row into view.
const rows = {};

/**
 * The list beside the map. It holds the same results as the markers, so
 * it doubles as the keyboard and screen-reader route to every artist the
 * map is showing -- a Leaflet pin is hard to reach any other way.
 */
const props = defineProps({
    artists: { type: Array, default: () => [] },
    meta: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    failed: { type: Boolean, default: false },
    pristine: { type: Boolean, default: true },
    selectedId: { type: Number, default: null },
    isFiltered: { type: Boolean, default: false },
    canFollow: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'hover', 'retry', 'clear-filters']);

const unit = computed(() => props.meta?.unit ?? 'mi');
const isEmpty = computed(() => !props.loading && !props.failed && !props.pristine && props.artists.length === 0);

// In cluster mode the list is a nearest-first sample, not everything in
// view; say so rather than letting the count look wrong.
const sampled = computed(() => props.meta?.mode === 'clusters');

const summary = computed(() => {
    if (!props.meta) {
        return '';
    }

    const total = props.meta.total;
    const noun = total === 1 ? 'artist' : 'artists';

    if (sampled.value) {
        return `${total.toLocaleString()} ${noun} here — showing the ${props.meta.returned} nearest`;
    }

    if (props.meta.truncated) {
        return `Showing ${props.meta.returned} of ${total.toLocaleString()} ${noun}`;
    }

    return `${total.toLocaleString()} ${noun}`;
});

// Keep the selected row visible when the selection came from the map.
watch(() => props.selectedId, (id) => {
    if (id && rows[id]) {
        rows[id].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
});
</script>

<template>
    <div class="flex h-full flex-col bg-riff-surface">
        <!-- Summary -->
        <div class="flex items-center gap-2 border-b border-riff-line px-4 py-2 text-xs text-riff-muted">
            <q-spinner v-if="loading" size="14px" color="primary" />
            <span aria-live="polite">{{ loading && pristine ? 'Finding artists…' : summary }}</span>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <!-- Loading skeletons, only before the first result -->
            <div v-if="loading && pristine" class="space-y-px" aria-hidden="true">
                <div v-for="n in 6" :key="n" class="flex items-center gap-3 px-4 py-3">
                    <q-skeleton type="QAvatar" size="44px" dark />
                    <div class="flex-1">
                        <q-skeleton type="text" width="60%" dark />
                        <q-skeleton type="text" width="40%" dark />
                    </div>
                </div>
            </div>

            <!-- Error -->
            <div v-else-if="failed" class="p-6 text-center">
                <q-icon name="cloud_off" size="32px" class="text-riff-muted" />
                <p class="mt-2 text-sm text-riff-text">Couldn't load artists for this area.</p>
                <q-btn
                    unelevated rounded no-caps color="primary" label="Try again"
                    padding="xs lg" class="mt-3" @click="emit('retry')"
                />
            </div>

            <!-- Empty -->
            <div v-else-if="isEmpty" class="p-6 text-center">
                <q-icon :name="isFiltered ? 'filter_alt_off' : 'travel_explore'" size="32px" class="text-riff-muted" />

                <template v-if="isFiltered">
                    <p class="mt-2 text-sm text-riff-text">No artists match these filters here.</p>
                    <q-btn
                        outline rounded no-caps color="grey-5" label="Clear filters"
                        padding="xs lg" class="mt-3" @click="emit('clear-filters')"
                    />
                </template>

                <template v-else>
                    <p class="mt-2 text-sm text-riff-text">No artists on the map here yet.</p>
                    <p class="mt-1 text-xs text-riff-muted">
                        Zoom out to widen the search — or if you make music here, be the first.
                    </p>
                    <q-btn
                        unelevated rounded no-caps color="primary" padding="xs lg" class="mt-3"
                        :label="canFollow ? 'Put yourself on the map' : 'Join Riff and add yourself'"
                        :href="canFollow ? route('artists.create') : route('register')"
                        type="a"
                    />
                </template>
            </div>

            <!-- Results -->
            <ul v-else class="divide-y divide-riff-line">
                <li v-for="artist in artists" :key="artist.id" :ref="(el) => (rows[artist.id] = el)">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-riff-surface-2 focus-visible:bg-riff-surface-2 focus-visible:outline-none"
                        :class="{ 'bg-riff-surface-2': artist.id === selectedId }"
                        :aria-current="artist.id === selectedId ? 'true' : undefined"
                        @click="emit('select', artist)"
                        @mouseenter="emit('hover', artist.id)"
                        @mouseleave="emit('hover', null)"
                        @focus="emit('hover', artist.id)"
                        @blur="emit('hover', null)"
                    >
                        <q-avatar size="44px" :color="artist.imageUrl ? '' : 'grey-9'" text-color="grey-5">
                            <img v-if="artist.imageUrl" :src="artist.imageUrl" :alt="`${artist.name} photo`" />
                            <q-icon v-else name="music_note" size="22px" />
                        </q-avatar>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="truncate text-sm font-semibold text-riff-text">{{ artist.name }}</span>
                                <span v-if="artist.distance !== null" class="shrink-0 text-xs text-riff-muted">
                                    {{ artist.distance }} {{ unit }}
                                </span>
                            </div>
                            <div class="truncate text-xs text-riff-muted">
                                {{ [artist.typeLabel, ...(artist.genres ?? [])].filter(Boolean).join(' · ') }}
                            </div>
                            <div class="truncate text-xs text-riff-muted">{{ artist.location }}</div>
                        </div>

                        <q-icon
                            v-if="artist.hasMusic"
                            name="play_circle"
                            size="20px"
                            class="shrink-0 text-riff-primary"
                        >
                            <q-tooltip>Has music to play</q-tooltip>
                        </q-icon>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
