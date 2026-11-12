<script setup>
import { computed, ref } from 'vue';
import { usePlaceSearch } from '@/Composables/useMapData';

/**
 * Search and filters. The same controls serve both layouts: inline on a
 * wide screen, inside a dialog on a narrow one, so there is one place to
 * change a filter and one set of options.
 *
 * Options come from the server (MapController::filterOptions) so what is
 * offered here always matches what the API will accept.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    activeCount: { type: Number, default: 0 },
    canFollow: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['reset', 'jump', 'locate', 'home', 'help']);

const { places, search: searchPlaces, clear: clearPlaces } = usePlaceSearch();

const dialogOpen = ref(false);
const showPlaces = ref(false);

const radiusOptions = computed(() => [
    { label: 'Visible area', value: null },
    ...props.options.radiusOptions.map((value) => ({ label: `${value} ${props.options.unit}`, value })),
]);

// Each kind toggles independently; the last one on can't be switched
// off, or the map would show nothing and look broken.
const toggleKind = (kind) => {
    const on = props.filters.kinds.includes(kind);

    if (on && props.filters.kinds.length === 1) {
        return;
    }

    const next = on
        ? props.filters.kinds.filter((k) => k !== kind)
        : [...props.filters.kinds, kind];

    // Kept in the canonical order so the URL is stable.
    props.filters.kinds = props.options.kinds.map((k) => k.value).filter((k) => next.includes(k));
};

const kindColor = { artists: 'primary', shows: 'amber-8', venues: 'teal-6' };
const showsOn = computed(() => props.filters.kinds.includes('shows'));

const genreOptions = computed(() => props.options.genres.map((g) => ({ label: g.name, value: g.slug })));
const typeOptions = computed(() => props.options.types.map((t) => ({ label: t.label, value: t.value })));

/**
 * One line of text for a multi-select in the toolbar: the name when one
 * is chosen, a count when several are. Chips would grow the field and
 * push the toolbar onto two lines.
 */
const summarise = (values, options, noun) => {
    if (!values.length) {
        return '';
    }

    if (values.length === 1) {
        return options.find((option) => option.value === values[0])?.label ?? '';
    }

    return `${values.length} ${noun}`;
};

const genreSummary = computed(() => summarise(props.filters.genres, genreOptions.value, 'genres'));
const typeSummary = computed(() => summarise(props.filters.types, typeOptions.value, 'types'));

const onSearchInput = (value) => {
    props.filters.q = value ?? '';
    searchPlaces(value);
    showPlaces.value = true;
};

const jumpTo = (place) => {
    showPlaces.value = false;
    clearPlaces();
    emit('jump', place);
};

const selectProps = {
    dark: true,
    dense: true,
    outlined: true,
    'label-color': 'grey-5',
    'bg-color': 'grey-9',
    color: 'primary',
    'options-dense': true,
    'emit-value': true,
    'map-options': true,
};
</script>

<template>
    <div class="flex flex-nowrap items-center gap-2 border-b border-riff-line bg-riff-surface px-2 py-2 sm:px-3">
        <!-- Search: filters artists, and offers towns to jump to -->
        <div class="relative min-w-0 flex-1 lg:max-w-xs lg:flex-none">
            <q-input
                :model-value="filters.q"
                dark dense outlined clearable
                bg-color="grey-9"
                color="primary"
                placeholder="Search artists or towns"
                aria-label="Search artists or towns"
                debounce="250"
                @update:model-value="onSearchInput"
                @focus="showPlaces = true"
                @blur="showPlaces = false"
            >
                <template #prepend><q-icon name="search" size="18px" /></template>
            </q-input>

            <!-- Town suggestions. mousedown fires before blur, so the
                 click is not swallowed by the input losing focus. -->
            <ul
                v-if="showPlaces && places.length"
                class="absolute z-[1200] mt-1 w-full overflow-hidden rounded-md border border-riff-line bg-riff-surface-2 shadow-xl"
            >
                <li class="px-3 pt-2 pb-1 text-[11px] tracking-wide text-riff-muted uppercase">Jump to</li>
                <li v-for="place in places" :key="`${place.name}-${place.region}`">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm text-riff-text hover:bg-riff-surface"
                        @mousedown.prevent="jumpTo(place)"
                    >
                        <span class="truncate">
                            <q-icon name="place" size="14px" class="mr-1 text-riff-muted" />
                            {{ place.name }}, {{ place.region }}
                        </span>
                        <span class="shrink-0 text-xs text-riff-muted">{{ place.artistCount }}</span>
                    </button>
                    <a
                        v-if="place.sceneUrl"
                        :href="place.sceneUrl"
                        class="flex items-center gap-1 px-3 pb-2 pl-8 text-xs text-riff-muted no-underline hover:text-riff-text"
                        @mousedown.prevent
                    >
                        <q-icon name="travel_explore" size="13px" /> See the {{ place.name }} scene
                    </a>
                </li>
            </ul>
        </div>

        <!-- Kinds: everything is on by default; these narrow the scene -->
        <div class="wide-only shrink-0 items-center gap-1" role="group" aria-label="Show on the map">
            <q-chip
                v-for="kind in options.kinds"
                :key="kind.value"
                clickable
                dense
                :outline="!filters.kinds.includes(kind.value)"
                :color="filters.kinds.includes(kind.value) ? kindColor[kind.value] : 'grey-7'"
                :text-color="filters.kinds.includes(kind.value) ? 'white' : 'grey-5'"
                :icon="kind.icon"
                :label="kind.label"
                :aria-pressed="filters.kinds.includes(kind.value)"
                class="m-0"
                @click="toggleKind(kind.value)"
            />
        </div>

        <!-- Inline controls on wide screens -->
        <div class="wide-only shrink-0 items-center gap-2">
            <q-select
                v-if="showsOn"
                v-model="filters.when"
                v-bind="selectProps"
                :options="options.windows"
                option-label="label" option-value="value"
                label="Shows"
                class="w-36"
                popup-content-class="riff-select-menu"
                aria-label="Which shows"
            />
            <q-select
                v-model="filters.genres"
                v-bind="selectProps"
                :options="genreOptions"
                :display-value="genreSummary"
                multiple
                label="Genres"
                class="w-44"
                popup-content-class="riff-select-menu"
                aria-label="Filter by genre"
            >
                <template #option="{ itemProps, opt, selected, toggleOption }">
                    <q-item v-bind="itemProps" dense>
                        <q-item-section side>
                            <q-checkbox :model-value="selected" dense color="primary" @update:model-value="toggleOption(opt)" />
                        </q-item-section>
                        <q-item-section>{{ opt.label }}</q-item-section>
                    </q-item>
                </template>
            </q-select>
            <q-select
                v-model="filters.types"
                v-bind="selectProps"
                :options="typeOptions"
                :display-value="typeSummary"
                multiple
                label="Type"
                class="w-40"
                popup-content-class="riff-select-menu"
                aria-label="Filter by artist type"
            >
                <template #option="{ itemProps, opt, selected, toggleOption }">
                    <q-item v-bind="itemProps" dense>
                        <q-item-section side>
                            <q-checkbox :model-value="selected" dense color="primary" @update:model-value="toggleOption(opt)" />
                        </q-item-section>
                        <q-item-section>{{ opt.label }}</q-item-section>
                    </q-item>
                </template>
            </q-select>
            <q-select
                v-model="filters.radius"
                v-bind="selectProps"
                :options="radiusOptions"
                label="Within"
                class="w-36"
                popup-content-class="riff-select-menu"
                aria-label="Search radius"
            />
            <q-btn flat dense no-caps color="grey-4" icon="tune" label="More">
                <q-menu>
                    <div class="min-w-[220px] bg-riff-surface-2 p-3">
                        <q-toggle dark v-model="filters.has_music" dense color="primary" label="Has music to play" />
                        <q-toggle dark v-model="filters.has_photo" dense color="primary" label="Has a photo" class="mt-2" />
                        <q-toggle
                            dark
                            v-if="canFollow"
                            v-model="filters.followed"
                            dense color="primary" label="Artists I follow" class="mt-2"
                        />
                        <q-separator color="grey-9" class="my-3" />
                        <q-select
                            v-model="filters.sort"
                            v-bind="selectProps"
                            :options="options.sorts"
                            option-label="label" option-value="value"
                            label="Sort by"
                        />
                    </div>
                </q-menu>
            </q-btn>
        </div>

        <!-- One button on narrow screens -->
        <q-btn
            flat dense no-caps color="grey-4" icon="tune" label="Filters"
            class="narrow-only shrink-0"
            @click="dialogOpen = true"
        >
            <q-badge v-if="activeCount" color="primary" floating rounded>{{ activeCount }}</q-badge>
        </q-btn>

        <q-btn
            v-if="activeCount"
            flat dense no-caps color="grey-5" icon="close" label="Clear"
            class="wide-only"
            @click="emit('reset')"
        />

        <div class="ml-auto flex shrink-0 items-center gap-1">
            <q-spinner v-if="busy" size="18px" color="primary" class="mr-1" />
            <q-btn flat dense round icon="my_location" color="grey-4" aria-label="Use my current location" @click="emit('locate')">
                <q-tooltip>Use my current location</q-tooltip>
            </q-btn>
            <q-btn flat dense round icon="home" color="grey-4" aria-label="Back to my area" @click="emit('home')">
                <q-tooltip>Back to my area</q-tooltip>
            </q-btn>
            <!-- Brings back the welcome overlay, which otherwise shows once. -->
            <q-btn flat dense round icon="help_outline" color="grey-4" aria-label="How Riff works" @click="emit('help')">
                <q-tooltip>How Riff works</q-tooltip>
            </q-btn>
        </div>

        <!-- Narrow-screen filters -->
        <q-dialog v-model="dialogOpen" position="bottom">
            <q-card dark class="w-full bg-riff-surface">
                <q-card-section class="row items-center">
                    <div class="text-subtitle1 text-weight-medium text-grey-3">Filters</div>
                    <q-space />
                    <q-btn v-close-popup flat dense round icon="close" color="grey-5" aria-label="Close filters" />
                </q-card-section>
                <q-separator color="grey-9" />
                <q-card-section class="q-gutter-md">
                    <div class="flex flex-wrap gap-1" role="group" aria-label="Show on the map">
                        <q-chip
                            v-for="kind in options.kinds"
                            :key="kind.value"
                            clickable
                            :outline="!filters.kinds.includes(kind.value)"
                            :color="filters.kinds.includes(kind.value) ? kindColor[kind.value] : 'grey-7'"
                            :text-color="filters.kinds.includes(kind.value) ? 'white' : 'grey-5'"
                            :icon="kind.icon"
                            :label="kind.label"
                            :aria-pressed="filters.kinds.includes(kind.value)"
                            @click="toggleKind(kind.value)"
                        />
                    </div>
                    <q-select v-if="showsOn" v-model="filters.when" v-bind="selectProps" :options="options.windows" option-label="label" option-value="value" label="Shows" popup-content-class="riff-select-menu" />
                    <q-select v-model="filters.genres" v-bind="selectProps" :options="genreOptions" multiple use-chips label="Genres" popup-content-class="riff-select-menu">
                        <template #selected-item="{ opt, index, removeAtIndex, tabindex }">
                            <q-chip dense removable color="grey-8" text-color="grey-2" :tabindex="tabindex" :remove-aria-label="`Remove ${opt.label}`" @remove="removeAtIndex(index)">{{ opt.label }}</q-chip>
                        </template>
                    </q-select>
                    <q-select v-model="filters.types" v-bind="selectProps" :options="typeOptions" multiple use-chips label="Type" popup-content-class="riff-select-menu">
                        <template #selected-item="{ opt, index, removeAtIndex, tabindex }">
                            <q-chip dense removable color="grey-8" text-color="grey-2" :tabindex="tabindex" :remove-aria-label="`Remove ${opt.label}`" @remove="removeAtIndex(index)">{{ opt.label }}</q-chip>
                        </template>
                    </q-select>
                    <q-select v-model="filters.radius" v-bind="selectProps" :options="radiusOptions" label="Within" popup-content-class="riff-select-menu" />
                    <q-select v-model="filters.sort" v-bind="selectProps" :options="options.sorts" option-label="label" option-value="value" label="Sort by" popup-content-class="riff-select-menu" />
                    <q-toggle dark v-model="filters.has_music" dense color="primary" label="Has music to play" />
                    <q-toggle dark v-model="filters.has_photo" dense color="primary" label="Has a photo" />
                    <q-toggle v-if="canFollow" v-model="filters.followed" dense color="primary" label="Artists I follow" />
                </q-card-section>
                <q-card-actions align="right" class="q-pb-md q-px-md">
                    <q-btn flat no-caps color="grey-5" label="Clear all" @click="emit('reset')" />
                    <q-btn v-close-popup unelevated rounded no-caps color="primary" label="Show results" padding="xs lg" />
                </q-card-actions>
            </q-card>
        </q-dialog>
    </div>
</template>

<style scoped>
/* Breakpoint matches the map page's own 1024px switch. */
.wide-only {
    display: none;
}

.narrow-only {
    display: inline-flex;
}

@media (min-width: 1024px) {
    .wide-only {
        display: flex;
    }

    .narrow-only {
        display: none;
    }
}
</style>
