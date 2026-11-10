<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * The report form, shared by every surface that can raise one: a map card,
 * an artist profile, a feed post. The subject is named by kind and id, the
 * same way the endpoint takes it.
 *
 * Reasons come from the server (shared as reportReasons) so the list here
 * is always the list validation accepts.
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    // 'artist' | 'post'
    type: { type: String, required: true },
    id: { type: Number, required: true },
    subjectName: { type: String, default: 'this' },
});

const emit = defineEmits(['update:modelValue']);

const reasons = computed(() => usePage().props.reportReasons ?? []);

const reason = ref(null);
const details = ref('');
const sending = ref(false);
const sent = ref(false);
const error = ref(null);

// Every open starts clean: a dialog that remembered the last complaint
// would invite sending it again by accident.
watch(() => props.modelValue, (open) => {
    if (open) {
        reason.value = null;
        details.value = '';
        sending.value = false;
        sent.value = false;
        error.value = null;
    }
});

const close = () => emit('update:modelValue', false);

const submit = () => {
    if (!reason.value || sending.value) {
        return;
    }

    sending.value = true;
    error.value = null;

    router.post(
        route('reports.store'),
        { type: props.type, id: props.id, reason: reason.value, details: details.value },
        {
            preserveScroll: true,
            // The map owns a Leaflet instance; a plain visit would throw it
            // away and rebuild the whole viewport behind the dialog.
            preserveState: true,
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? 'Something went wrong. Try again.';
            },
            onSuccess: () => {
                sent.value = true;
            },
            onFinish: () => {
                sending.value = false;
            },
        },
    );
};
</script>

<template>
    <q-dialog :model-value="modelValue" @update:model-value="emit('update:modelValue', $event)">
        <q-card dark class="riff-report bg-riff-surface">
            <template v-if="sent">
                <q-card-section class="items-start">
                    <div class="flex items-center gap-2 text-riff-text">
                        <q-icon name="check_circle" color="positive" size="22px" />
                        <h2 class="m-0 text-base font-semibold">Thanks — that's with our moderators</h2>
                    </div>
                    <p class="m-0 mt-2 text-sm leading-relaxed text-riff-muted">
                        We look at every report. You won't hear back about this one, and
                        {{ subjectName }} isn't told who reported them.
                    </p>
                    <p v-if="type === 'artist'" class="m-0 mt-2 text-sm leading-relaxed text-riff-muted">
                        If you'd rather not see them in the meantime, block them from the
                        same menu.
                    </p>
                </q-card-section>
                <q-card-actions align="right">
                    <q-btn unelevated rounded no-caps color="primary" padding="xs lg" label="Done" @click="close" />
                </q-card-actions>
            </template>

            <template v-else>
                <q-card-section>
                    <h2 class="m-0 text-base font-semibold text-riff-text">
                        Report {{ subjectName }}
                    </h2>
                    <p class="m-0 mt-1 text-sm leading-relaxed text-riff-muted">
                        Tell us what's wrong and a moderator will look at it. Reports are
                        anonymous to the person reported.
                    </p>
                </q-card-section>

                <q-card-section class="q-gutter-md pt-0">
                    <q-select
                        v-model="reason"
                        :options="reasons"
                        dark dense outlined
                        emit-value map-options
                        bg-color="grey-9"
                        color="primary"
                        label="Reason"
                        label-color="grey-5"
                        popup-content-class="riff-select-menu"
                        :error="Boolean(error) && !reason"
                    />
                    <q-input
                        v-model="details"
                        dark dense outlined
                        type="textarea"
                        rows="3"
                        maxlength="1000"
                        counter
                        bg-color="grey-9"
                        color="primary"
                        label="Anything else? (optional)"
                        label-color="grey-5"
                    />
                    <p v-if="error" class="m-0 text-sm text-red-4" role="alert">{{ error }}</p>
                </q-card-section>

                <q-card-actions align="right" class="q-px-md q-pb-md">
                    <q-btn flat no-caps color="grey-5" label="Cancel" @click="close" />
                    <q-btn
                        unelevated rounded no-caps
                        color="primary"
                        padding="xs lg"
                        label="Send report"
                        :disable="!reason"
                        :loading="sending"
                        @click="submit"
                    />
                </q-card-actions>
            </template>
        </q-card>
    </q-dialog>
</template>

<style scoped>
.riff-report {
    width: 100%;
    max-width: 440px;
}
</style>
