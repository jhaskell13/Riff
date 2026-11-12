<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The side-panel card for a selected show or venue. Built from the pin's
 * own payload (ShowMarkerResource / VenueMarkerResource), so opening it
 * costs no request; the full page is one tap away.
 */
const props = defineProps({
    place: { type: Object, required: true },
    unit: { type: String, default: 'mi' },
});

const emit = defineEmits(['close', 'collapse']);

const isShow = computed(() => props.place.kind === 'show');
const isListing = computed(() => props.place.kind === 'listing');
</script>

<template>
    <div class="flex h-full flex-col bg-riff-surface">
        <div class="flex items-center justify-between gap-1 border-b border-riff-line px-2 py-2">
            <q-btn flat dense no-caps icon="arrow_back" label="Back to list" color="grey-5" @click="emit('close')" />
            <q-btn
                flat dense round size="sm" color="grey-5"
                class="collapse-btn"
                aria-label="Hide the panel"
                @click="emit('collapse')"
            >
                <q-icon class="collapse-btn__wide" name="chevron_left" size="20px" />
                <q-icon class="collapse-btn__narrow" name="expand_more" size="20px" />
                <q-tooltip>Hide panel</q-tooltip>
            </q-btn>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-4">
            <!-- Show -->
            <template v-if="isShow">
                <div class="flex items-start gap-3">
                    <div class="date-leaf shrink-0">
                        <span class="date-leaf__month">{{ place.month }}</span>
                        <span class="date-leaf__day">{{ place.day }}</span>
                    </div>
                    <div class="min-w-0">
                        <h2 class="m-0 text-lg leading-tight font-semibold break-words text-riff-text">{{ place.title }}</h2>
                        <p class="m-0 mt-1 text-sm text-riff-muted">{{ place.dateLabel }} · {{ place.timeLabel }}</p>
                    </div>
                </div>

                <div class="mt-4 space-y-2 text-sm">
                    <div class="flex items-start gap-2 text-riff-text">
                        <q-icon :name="place.isOneOff ? 'cottage' : 'storefront'" size="18px" class="mt-px text-riff-muted" />
                        <div>
                            <div>{{ place.place }}</div>
                            <div class="text-xs text-riff-muted">
                                {{ place.area }}<template v-if="place.distance !== null"> · {{ place.distance }} {{ unit }} away</template>
                            </div>
                            <div v-if="place.locationNote" class="mt-1 text-xs text-riff-text">
                                <q-icon name="info" size="13px" /> {{ place.locationNote }}
                            </div>
                        </div>
                    </div>
                    <div v-if="place.price" class="flex items-center gap-2 text-riff-text">
                        <q-icon name="sell" size="18px" class="text-riff-muted" /> {{ place.price }}
                    </div>
                    <div v-if="place.isImported" class="text-xs text-riff-muted">
                        <q-icon name="cloud_download" size="13px" /> Imported listing — not confirmed by the artists
                    </div>
                </div>

                <Link
                    :href="place.url"
                    class="mt-5 inline-flex items-center gap-1 rounded-full bg-riff-show px-4 py-1.5 text-sm font-medium text-black no-underline"
                >
                    See the show <q-icon name="arrow_forward" size="16px" />
                </Link>
            </template>

            <!-- Listing -->
            <template v-else-if="isListing">
                <div class="flex items-start gap-3">
                    <q-avatar size="48px" class="bg-riff-listing" text-color="white">
                        <q-icon :name="place.icon" size="24px" />
                    </q-avatar>
                    <div class="min-w-0">
                        <p class="m-0 text-xs font-medium text-riff-listing">{{ place.typeLabel }}</p>
                        <h2 class="m-0 text-lg leading-tight font-semibold break-words text-riff-text">{{ place.title }}</h2>
                        <p class="m-0 mt-1 text-xs text-riff-muted">
                            {{ place.postedBy ? `${place.postedBy} · ` : '' }}{{ place.area }} · {{ place.postedLabel }}
                        </p>
                    </div>
                </div>
                <div v-if="place.instruments.length" class="mt-3 flex flex-wrap gap-1">
                    <span v-for="i in place.instruments" :key="i" class="rounded-full bg-riff-surface-2 px-2 py-0.5 text-xs text-riff-text">{{ i }}</span>
                </div>
                <p class="m-0 mt-3 text-sm whitespace-pre-line text-riff-text">{{ place.excerpt }}</p>
                <Link
                    :href="place.url"
                    class="mt-5 inline-flex items-center gap-1 rounded-full bg-riff-listing px-4 py-1.5 text-sm font-medium text-white no-underline"
                >
                    Read and reply <q-icon name="arrow_forward" size="16px" />
                </Link>
            </template>

            <!-- Venue -->
            <template v-else>
                <div class="flex items-start gap-3">
                    <q-avatar size="48px" class="bg-riff-venue" text-color="white">
                        <q-icon name="storefront" size="24px" />
                    </q-avatar>
                    <div class="min-w-0">
                        <h2 class="m-0 text-lg leading-tight font-semibold break-words text-riff-text">{{ place.name }}</h2>
                        <p class="m-0 mt-1 text-sm text-riff-muted">{{ place.kindLabel }}</p>
                    </div>
                </div>

                <div class="mt-4 space-y-2 text-sm text-riff-text">
                    <div class="flex items-start gap-2">
                        <q-icon name="place" size="18px" class="mt-px text-riff-muted" />
                        <div>
                            <div>{{ place.area }}</div>
                            <div v-if="place.isPrivate" class="text-xs text-riff-muted">Address shared by the hosts</div>
                            <div v-if="place.distance !== null" class="text-xs text-riff-muted">{{ place.distance }} {{ unit }} away</div>
                        </div>
                    </div>
                    <div v-if="place.agePolicyLabel" class="flex items-center gap-2">
                        <q-icon name="badge" size="18px" class="text-riff-muted" /> {{ place.agePolicyLabel }}
                    </div>
                    <div class="flex items-center gap-2">
                        <q-icon name="event" size="18px" class="text-riff-muted" />
                        {{ place.upcomingCount ? `${place.upcomingCount} upcoming ${place.upcomingCount === 1 ? 'show' : 'shows'}` : 'No shows listed yet' }}
                    </div>
                </div>

                <Link
                    :href="place.url"
                    class="mt-5 inline-flex items-center gap-1 rounded-full bg-riff-venue px-4 py-1.5 text-sm font-medium text-white no-underline"
                >
                    See the venue <q-icon name="arrow_forward" size="16px" />
                </Link>
            </template>
        </div>
    </div>
</template>

<style scoped>
.date-leaf {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: 10px;
    background: var(--color-riff-show);
    color: #000;
    line-height: 1;
}

.date-leaf__month {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
}

.date-leaf__day {
    font-size: 20px;
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
