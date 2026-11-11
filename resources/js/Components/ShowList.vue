<script setup>
import { Link } from '@inertiajs/vue3';

// A compact list of ShowCardResource items. Dates and times arrive already
// formatted in each show's own timezone.
defineProps({
    shows: { type: Array, default: () => [] },
    empty: { type: String, default: 'No upcoming shows.' },
});
</script>

<template>
    <div v-if="shows.length" class="column gap-3">
        <Link
            v-for="show in shows"
            :key="show.id"
            :href="show.url"
            class="row no-wrap items-center gap-3 rounded-borders q-pa-sm show-row"
        >
            <div class="date-chip column items-center justify-center text-center">
                <div class="text-caption text-grey-5 leading-none">{{ show.dateLabel.split(', ')[0] }}</div>
                <div class="text-subtitle2 text-weight-bold text-grey-2 leading-tight">{{ show.dateLabel.split(', ')[1] }}</div>
            </div>
            <div class="col leading-tight">
                <div class="text-body2 text-weight-medium text-grey-2">
                    {{ show.title }}
                    <span v-if="show.isCancelled" class="text-caption text-negative q-ml-xs">Cancelled</span>
                    <span v-if="show.isImported" class="text-caption text-grey-6 q-ml-xs">Imported listing</span>
                </div>
                <div class="text-caption text-grey-6">
                    {{ show.timeLabel }} · {{ show.place }}<template v-if="show.area"> · {{ show.area }}</template>
                </div>
            </div>
            <q-icon name="chevron_right" color="grey-7" />
        </Link>
    </div>
    <p v-else class="text-caption text-grey-6 m-0">{{ empty }}</p>
</template>

<style scoped>
.show-row {
    background: #262626;
    text-decoration: none;
}

.show-row:hover {
    background: #2e2e2e;
}

.date-chip {
    min-width: 52px;
    padding: 4px 6px;
    border-radius: 8px;
    background: #1a1a1a;
}
</style>
