<script setup>
import { computed, nextTick, ref, watch } from 'vue';

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
    // The scene: upcoming shows (soonest first) and venues, each only
    // when its kind is switched on.
    shows: { type: Array, default: () => [] },
    venues: { type: Array, default: () => [] },
    listings: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => ['artists', 'shows', 'venues', 'listings'] },
    windowLabel: { type: String, default: '' },
    selectedPlaceKey: { type: String, default: null },
    meta: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    failed: { type: Boolean, default: false },
    pristine: { type: Boolean, default: true },
    selectedId: { type: Number, default: null },
    isFiltered: { type: Boolean, default: false },
    canFollow: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'select-place', 'hover', 'retry', 'clear-filters', 'collapse']);

const has = (kind) => props.kinds.includes(kind);
const showsHere = computed(() => (has('shows') ? props.shows : []));
const venuesHere = computed(() => (has('venues') ? props.venues : []));
const listingsHere = computed(() => (has('listings') ? props.listings : []));
const artistsHere = computed(() => (has('artists') ? props.artists : []));

const unit = computed(() => props.meta?.unit ?? 'mi');
const isEmpty = computed(() => !props.loading && !props.failed && !props.pristine
    && artistsHere.value.length === 0 && showsHere.value.length === 0
    && venuesHere.value.length === 0 && listingsHere.value.length === 0);

// In cluster mode the list is a nearest-first sample, not everything in
// view; say so rather than letting the count look wrong.
const sampled = computed(() => props.meta?.mode === 'clusters');

/* ---- Tabs: one kind at a time, so no section is buried under another --- */

const TAB_META = {
    shows: { label: 'Shows', icon: 'event', color: 'text-riff-show' },
    artists: { label: 'Artists', icon: 'groups', color: 'text-riff-primary' },
    venues: { label: 'Venues', icon: 'storefront', color: 'text-riff-venue' },
    listings: { label: 'Wanted', icon: 'campaign', color: 'text-riff-listing' },
};
// Shows first: "what's on" is the first question a scene answers.
const TAB_ORDER = ['shows', 'artists', 'venues', 'listings'];

const compact = (n) => (n >= 1000 ? `${Math.round(n / 100) / 10}k` : String(n));

const countFor = (kind) => ({
    shows: showsHere.value.length,
    // The artists' true total (clusters included), not just the rows sent.
    artists: props.meta?.total ?? artistsHere.value.length,
    venues: venuesHere.value.length,
    listings: listingsHere.value.length,
}[kind]);

const tabs = computed(() => TAB_ORDER.filter(has).map((kind) => ({
    kind,
    ...TAB_META[kind],
    count: countFor(kind),
})));

const tab = ref(null);
// Until the listener picks a tab, follow the data to the first kind with
// something in it; after that, stay where they put us.
let picked = false;

watch(tabs, (list) => {
    const kinds = list.map((t) => t.kind);

    if (tab.value && kinds.includes(tab.value) && picked) {
        return;
    }

    if (!kinds.includes(tab.value)) {
        picked = false;
    }

    if (!picked) {
        tab.value = list.find((t) => t.count > 0)?.kind ?? kinds[0] ?? null;
    }
}, { immediate: true });

const scroller = ref(null);

const choose = (kind) => {
    picked = true;

    if (tab.value !== kind) {
        tab.value = kind;
        scroller.value?.scrollTo({ top: 0 });
    }
};

// Arrow keys move along the tablist (the WAI-ARIA tabs pattern).
const tabButtons = {};
const onTabKey = (event, index) => {
    const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];

    if (!step) {
        return;
    }

    event.preventDefault();
    const next = tabs.value[(index + step + tabs.value.length) % tabs.value.length];
    choose(next.kind);
    tabButtons[next.kind]?.focus();
};

// A pick on the map shows its row: switch to that kind's tab.
watch(() => props.selectedId, (id) => id && has('artists') && (tab.value = 'artists'));
watch(() => props.selectedPlaceKey, (key) => {
    const kind = { show: 'shows', venue: 'venues', listing: 'listings' }[key?.split(':')[0]] ?? null;

    if (kind && has(kind)) {
        tab.value = kind;
    }
});

const tabEmpty = computed(() => ({
    shows: `No shows here ${props.windowLabel ? `in the ${props.windowLabel.toLowerCase()}` : 'yet'}.`,
    artists: 'No artists on the map here.',
    venues: 'No active venues here.',
    listings: 'Nobody here is looking for anyone right now.',
}[tab.value]));

// In cluster mode the artist list is a nearest-first sample.
const artistNote = computed(() => {
    if (!props.meta) {
        return '';
    }

    if (sampled.value) {
        return `Showing the ${props.meta.returned} nearest`;
    }

    return props.meta.truncated ? `Showing ${props.meta.returned} of ${props.meta.total.toLocaleString()}` : '';
});

const placeKey = (place) => `${place.kind}:${place.id}`;

// Keep the selected row visible when the selection came from the map
// (after the tab switch above has rendered it).
watch(() => props.selectedId, async (id) => {
    await nextTick();

    if (id && rows[id]) {
        rows[id].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
});
</script>

<template>
    <div class="flex h-full flex-col bg-riff-surface">
        <!-- Tabs: full-width, underlined, one per kind switched on. Styled as
             tabs on purpose -- not the filter bar's pill toggles, which
             switch kinds on and off; these only choose what to list. -->
        <div class="flex items-stretch border-b border-riff-line">
            <div v-if="loading && pristine" class="flex flex-1 items-center gap-2 px-4 py-3 text-xs text-riff-muted" aria-live="polite">
                <q-spinner size="14px" color="primary" /> Finding the scene…
            </div>
            <div
                v-else
                role="tablist"
                aria-label="What to list"
                class="grid min-w-0 flex-1"
                :style="{ gridTemplateColumns: `repeat(${tabs.length}, minmax(0, 1fr))` }"
            >
                <button
                    v-for="(t, index) in tabs"
                    :id="`list-tab-${t.kind}`"
                    :key="t.kind"
                    :ref="(el) => (tabButtons[t.kind] = el)"
                    type="button"
                    role="tab"
                    class="list-tab"
                    :class="[`list-tab--${t.kind}`, { 'list-tab--active': tab === t.kind }]"
                    :aria-selected="tab === t.kind"
                    :aria-controls="`list-panel-${t.kind}`"
                    :aria-label="`${t.label} (${t.count})`"
                    :tabindex="tab === t.kind ? 0 : -1"
                    @click="choose(t.kind)"
                    @keydown="onTabKey($event, index)"
                >
                    <span class="flex items-center gap-1">
                        <q-icon :name="t.icon" size="16px" :class="t.color" />
                        <span class="list-tab__count">{{ compact(t.count) }}</span>
                    </span>
                    <span class="list-tab__label">{{ t.label }}</span>
                </button>
            </div>
            <div class="flex shrink-0 items-center border-l border-riff-line px-1">
                <q-spinner v-if="loading && !pristine" size="14px" color="primary" class="mr-1" />
                <q-btn
                    flat dense round size="sm" color="grey-5"
                    class="collapse-btn"
                    aria-label="Hide the list"
                    @click="emit('collapse')"
                >
                    <q-icon class="collapse-btn__wide" name="chevron_left" size="20px" />
                    <q-icon class="collapse-btn__narrow" name="expand_more" size="20px" />
                    <q-tooltip>Hide list</q-tooltip>
                </q-btn>
            </div>
        </div>

        <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto">
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
                <p class="mt-2 text-sm text-riff-text">Couldn't load this area.</p>
                <q-btn
                    unelevated rounded no-caps color="primary" label="Try again"
                    padding="xs lg" class="mt-3" @click="emit('retry')"
                />
            </div>

            <!-- Empty -->
            <div v-else-if="isEmpty" class="p-6 text-center">
                <q-icon :name="isFiltered ? 'filter_alt_off' : 'travel_explore'" size="32px" class="text-riff-muted" />

                <template v-if="isFiltered">
                    <p class="mt-2 text-sm text-riff-text">Nothing matches these filters here.</p>
                    <q-btn
                        outline rounded no-caps color="grey-5" label="Clear filters"
                        padding="xs lg" class="mt-3" @click="emit('clear-filters')"
                    />
                </template>

                <template v-else>
                    <p class="mt-2 text-sm text-riff-text">Nothing on the map here yet.</p>
                    <p class="mt-1 text-xs text-riff-muted">
                        Zoom out to widen the search — or if you make music here, be the first.
                    </p>
                    <q-btn
                        unelevated rounded no-caps color="primary" padding="xs lg" class="mt-3"
                        :label="canFollow ? 'Put yourself on the map' : 'Join Riff and add yourself'"
                        :href="canFollow ? route('artists.create') : route('register', { intent: 'artist' })"
                        type="a"
                    />
                </template>
            </div>

            <!-- Results: one kind at a time -->
            <div v-else :id="`list-panel-${tab}`" role="tabpanel" :aria-labelledby="`list-tab-${tab}`">
            <p v-if="tab === 'shows' && windowLabel && showsHere.length" class="tab-note">{{ windowLabel }} · soonest first</p>
            <p v-else-if="tab === 'artists' && artistNote" class="tab-note">{{ artistNote }}</p>
            <p v-else-if="tab === 'venues' && venuesHere.length" class="tab-note">Places with music on — nearest first</p>

            <div v-if="countFor(tab) === 0" class="p-6 text-center text-sm text-riff-muted">{{ tabEmpty }}</div>

            <template v-if="tab === 'shows' && showsHere.length">
                <ul class="divide-y divide-riff-line">
                    <li v-for="show in showsHere" :key="`show-${show.id}`">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-riff-surface-2 focus-visible:bg-riff-surface-2 focus-visible:outline-none"
                            :class="{ 'bg-riff-surface-2': placeKey(show) === selectedPlaceKey }"
                            @click="emit('select-place', show)"
                        >
                            <div class="date-chip shrink-0">
                                <span class="date-chip__month">{{ show.month }}</span>
                                <span class="date-chip__day">{{ show.day }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="line-clamp-2 text-sm font-semibold break-words text-riff-text">{{ show.title }}</div>
                                <div class="line-clamp-1 text-xs text-riff-muted">{{ show.dateLabel }} · {{ show.timeLabel }}</div>
                                <div class="line-clamp-1 text-xs text-riff-muted">
                                    <q-icon v-if="show.isOneOff" name="cottage" size="12px" /> {{ show.place }}
                                </div>
                            </div>
                            <span v-if="show.distance !== null" class="w-12 shrink-0 self-start pt-0.5 text-right text-xs whitespace-nowrap text-riff-muted">
                                {{ show.distance }} {{ unit }}
                            </span>
                        </button>
                    </li>
                </ul>
            </template>

            <ul v-if="tab === 'artists' && artistsHere.length" class="divide-y divide-riff-line">
                <li v-for="artist in artistsHere" :key="artist.id" :ref="(el) => (rows[artist.id] = el)">
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
                            <div
                                class="line-clamp-2 text-sm font-semibold break-words text-riff-text"
                                :title="artist.name"
                            >{{ artist.name }}</div>
                            <div class="line-clamp-1 text-xs break-words text-riff-muted">
                                {{ [artist.typeLabel, ...(artist.genres ?? [])].filter(Boolean).join(' · ') }}
                            </div>
                            <div class="line-clamp-1 text-xs break-words text-riff-muted" :title="artist.location">
                                {{ artist.location }}
                            </div>
                        </div>

                        <!-- Fixed column, so distances line up whether or not a
                             row has music. -->
                        <div class="flex w-12 shrink-0 flex-col items-end gap-1 self-start pt-0.5">
                            <span v-if="artist.distance !== null" class="text-xs whitespace-nowrap text-riff-muted">
                                {{ artist.distance }} {{ unit }}
                            </span>
                            <q-icon v-if="artist.hasMusic" name="play_circle" size="20px" class="text-riff-primary">
                                <q-tooltip>Has music to play</q-tooltip>
                            </q-icon>
                        </div>
                    </button>
                </li>
            </ul>

            <template v-if="tab === 'venues' && venuesHere.length">
                <ul class="divide-y divide-riff-line">
                    <li v-for="venue in venuesHere" :key="`venue-${venue.id}`">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-riff-surface-2 focus-visible:bg-riff-surface-2 focus-visible:outline-none"
                            :class="{ 'bg-riff-surface-2': placeKey(venue) === selectedPlaceKey }"
                            @click="emit('select-place', venue)"
                        >
                            <q-avatar size="44px" class="bg-riff-venue" text-color="white">
                                <q-icon name="storefront" size="22px" />
                            </q-avatar>
                            <div class="min-w-0 flex-1">
                                <div class="line-clamp-2 text-sm font-semibold break-words text-riff-text">{{ venue.name }}</div>
                                <div class="line-clamp-1 text-xs text-riff-muted">{{ venue.kindLabel }} · {{ venue.area }}</div>
                                <div class="line-clamp-1 text-xs text-riff-muted">
                                    {{ venue.upcomingCount ? `${venue.upcomingCount} upcoming` : 'No shows listed yet' }}
                                </div>
                            </div>
                            <span v-if="venue.distance !== null" class="w-12 shrink-0 self-start pt-0.5 text-right text-xs whitespace-nowrap text-riff-muted">
                                {{ venue.distance }} {{ unit }}
                            </span>
                        </button>
                    </li>
                </ul>
            </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
.list-tab {
    position: relative;
    display: flex;
    min-width: 0;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    padding: 8px 4px 7px;
    border: 0;
    background: transparent;
    color: var(--color-riff-muted);
    cursor: pointer;
}

.list-tab:hover {
    background: var(--color-riff-surface-2);
    color: var(--color-riff-text);
}

.list-tab:focus-visible {
    outline: 2px solid #fff;
    outline-offset: -2px;
}

/* The underline is the tab cue -- the filter bar's kind toggles are
   filled pills, so the two never read as the same control. */
.list-tab::after {
    content: '';
    position: absolute;
    right: 10%;
    bottom: -1px;
    left: 10%;
    height: 3px;
    border-radius: 3px 3px 0 0;
    background: transparent;
}

.list-tab--active {
    color: var(--color-riff-text);
}

.list-tab--active.list-tab--artists::after { background: var(--color-riff-primary); }
.list-tab--active.list-tab--shows::after { background: var(--color-riff-show); }
.list-tab--active.list-tab--venues::after { background: var(--color-riff-venue); }
.list-tab--active.list-tab--listings::after { background: var(--color-riff-listing); }

.list-tab__count {
    font-size: 0.8rem;
    font-weight: 700;
}

.list-tab__label {
    max-width: 100%;
    overflow: hidden;
    font-size: 0.68rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.tab-note {
    margin: 0;
    padding: 8px 16px 4px;
    font-size: 11px;
    color: var(--color-riff-muted);
}

.date-chip {
    display: flex;
    width: 44px;
    height: 44px;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: color-mix(in srgb, var(--color-riff-show) 18%, transparent);
    color: var(--color-riff-show);
    line-height: 1;
}

.date-chip__month {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
}

.date-chip__day {
    font-size: 17px;
    font-weight: 700;
}

.collapse-btn__narrow {
    display: none;
}

@media (max-width: 1023px) {
    .collapse-btn__wide {
        display: none;
    }

    .collapse-btn__narrow {
        display: inline-flex;
    }
}
</style>
