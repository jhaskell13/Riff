<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import ReportDialog from '@/Components/ReportDialog.vue';

/**
 * Report (to moderators) and block (for yourself), deliberately in one menu.
 * Rendered only for signed-in viewers -- guests can do neither.
 */
const props = defineProps({
    artistId: { type: Number, required: true },
    artistName: { type: String, default: 'this artist' },
    isBlocked: { type: Boolean, default: false },
    canBlock: { type: Boolean, default: true },
    // Quasar colour name, so the menu can sit on both dark surfaces.
    color: { type: String, default: 'grey-5' },
});

const emit = defineEmits(['blocked', 'unblocked']);

const reporting = ref(false);
const pending = ref(false);
const confirmBlock = ref(false);

const block = () => {
    if (pending.value) {
        return;
    }

    pending.value = true;

    router.post(route('artists.block', props.artistId), {}, {
        preserveScroll: true,
        // Or the map page would tear down its Leaflet instance.
        preserveState: true,
        onSuccess: () => emit('blocked', props.artistId),
        onFinish: () => {
            pending.value = false;
            confirmBlock.value = false;
        },
    });
};

const unblock = () => {
    if (pending.value) {
        return;
    }

    pending.value = true;

    router.delete(route('artists.unblock', props.artistId), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('unblocked', props.artistId),
        onFinish: () => (pending.value = false),
    });
};
</script>

<template>
    <span>
        <q-btn
            flat dense round
            size="sm"
            icon="more_vert"
            :color="color"
            :loading="pending"
            :aria-label="`More options for ${artistName}`"
        >
            <q-menu dark anchor="bottom right" self="top right" class="bg-riff-surface-2">
                <q-list dark style="min-width: 200px">
                    <q-item v-close-popup clickable @click="reporting = true">
                        <q-item-section avatar><q-icon name="flag" size="20px" /></q-item-section>
                        <q-item-section>Report artist</q-item-section>
                    </q-item>

                    <q-item v-if="isBlocked" v-close-popup clickable @click="unblock">
                        <q-item-section avatar><q-icon name="visibility" size="20px" /></q-item-section>
                        <q-item-section>Unblock</q-item-section>
                    </q-item>
                    <q-item
                        v-else-if="canBlock"
                        v-close-popup
                        clickable
                        @click="confirmBlock = true"
                    >
                        <q-item-section avatar><q-icon name="block" size="20px" /></q-item-section>
                        <q-item-section>Block</q-item-section>
                    </q-item>
                </q-list>
            </q-menu>
        </q-btn>

        <ReportDialog
            v-model="reporting"
            type="artist"
            :id="artistId"
            :subject-name="artistName"
        />

        <q-dialog v-model="confirmBlock">
            <q-card dark class="riff-confirm bg-riff-surface">
                <q-card-section>
                    <h2 class="m-0 text-base font-semibold text-riff-text">Block {{ artistName }}?</h2>
                    <p class="m-0 mt-2 text-sm leading-relaxed text-riff-muted">
                        They'll disappear from your map and your feed, and you'll stop
                        following them. They aren't told, and they stay on everyone
                        else's map. You can undo this in your profile settings.
                    </p>
                </q-card-section>
                <q-card-actions align="right" class="q-px-md q-pb-md">
                    <q-btn v-close-popup flat no-caps color="grey-5" label="Cancel" />
                    <q-btn
                        unelevated rounded no-caps
                        color="negative"
                        padding="xs lg"
                        label="Block"
                        :loading="pending"
                        @click="block"
                    />
                </q-card-actions>
            </q-card>
        </q-dialog>
    </span>
</template>

<style scoped>
.riff-confirm {
    width: 100%;
    max-width: 420px;
}
</style>
