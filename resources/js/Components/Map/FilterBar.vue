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

const emit = defineEmits(['reset', 'jump', 'locate', 'home']);

const { places, search: searchPlaces, clear: clearPlaces } = usePlaceSearch();

const dialogOpen = ref(false);
const showPlaces = ref(false);

const radiusOptions = computed(() => [
    { label: 'Visible area', value: null },
    ...props.options.radiusOptions.map((value) => ({ label: `${value} ${props.options.unit}`, value })),
]);

const genreOptions = computed(() => props.options.genres.map((g) => ({ label: g.name, value: g.slug })));
const typeOptions = computed(() => props.options.types.map((t) => ({ label: t.label, value: t.value })));

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
    dense: true,
    outlined: true,
    'bg-color': 'grey-9',
    color: 'primary',
    'options-dense': true,
    'emit-value': true,
    'map-options': true,
};
</script>

<template>
    <div class="flex items-center gap-2 border-b border-riff-line bg-riff-surface px-3 py-2">
        <!-- Search: filters artists, and offers towns to jump to -->
        <div class="relative w-full max-w-xs">
            <q-input
                :model-value="filters.q"
                dense outlined clearable
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
                </li>
            </ul>
        </div>

        <!-- Inline controls on wide screens -->
        <div class="hidden items-center gap-2 lg:flex">
            <q-select
                v-model="filters.genres"
                v-bind="selectProps"
                :options="genreOptions"
                multiple use-chips
                label="Genres"
                class="min-w-[150px]"
                aria-label="Filter by genre"
            />
            <q-select
                v-model="filters.types"
                v-bind="selectProps"
                :options="typeOptions"
                multiple
                label="Type"
                class="min-w-[120px]"
                aria-label="Filter by artist type"
            />
            <q-select
                v-model="filters.radius"
                v-bind="selectProps"
                :options="radiusOptions"
                label="Within"
                class="min-w-[130px]"
                aria-label="Search radius"
            />
            <q-btn flat dense no-caps color="grey-4" icon="tune" label="More">
                <q-menu>
                    <div class="min-w-[220px] bg-riff-surface-2 p-3">
                        <q-toggle v-model="filters.has_music" dense color="primary" label="Has music to play" />
                        <q-toggle v-model="filters.has_photo" dense color="primary" label="Has a photo" class="mt-2" />
                        <q-toggle
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
            class="lg:hidden"
            @click="dialogOpen = true"
        >
            <q-badge v-if="activeCount" color="primary" floating rounded>{{ activeCount }}</q-badge>
        </q-btn>

        <q-btn
            v-if="activeCount"
            flat dense no-caps color="grey-5" icon="close" label="Clear"
            class="hidden lg:flex"
            @click="emit('reset')"
        />

        <div class="ml-auto flex items-center gap-1">
            <q-spinner v-if="busy" size="18px" color="primary" class="mr-1" />
            <q-btn flat dense round icon="my_location" color="grey-4" @click="emit('locate')">
                <q-tooltip>Use my current location</q-tooltip>
            </q-btn>
            <q-btn flat dense round icon="home" color="grey-4" @click="emit('home')">
                <q-tooltip>Back to my area</q-tooltip>
            </q-btn>
        </div>

        <!-- Narrow-screen filters -->
        <q-dialog v-model="dialogOpen" position="bottom">
            <q-card class="w-full bg-riff-surface">
                <q-card-section class="row items-center">
                    <div class="text-subtitle1 text-weight-medium text-grey-3">Filters</div>
                    <q-space />
                    <q-btn v-close-popup flat dense round icon="close" color="grey-5" />
                </q-card-section>
                <q-separator color="grey-9" />
                <q-card-section class="q-gutter-md">
                    <q-select v-model="filters.genres" v-bind="selectProps" :options="genreOptions" multiple use-chips label="Genres" />
                    <q-select v-model="filters.types" v-bind="selectProps" :options="typeOptions" multiple label="Type" />
                    <q-select v-model="filters.radius" v-bind="selectProps" :options="radiusOptions" label="Within" />
                    <q-select v-model="filters.sort" v-bind="selectProps" :options="options.sorts" option-label="label" option-value="value" label="Sort by" />
                    <q-toggle v-model="filters.has_music" dense color="primary" label="Has music to play" />
                    <q-toggle v-model="filters.has_photo" dense color="primary" label="Has a photo" />
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
