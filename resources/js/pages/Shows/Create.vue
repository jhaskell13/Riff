<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ShowFields from '@/Components/ShowFields.vue';
import { showPayload } from '@/Components/showPayload';

const props = defineProps({
    hostOptions: { type: Array, default: () => [] },
    agePolicies: { type: Array, default: () => [] },
    maxLineup: { type: Number, default: 12 },
    hostArtistId: { type: Number, default: null },
});

const defaultHost = props.hostOptions.some((o) => o.value === props.hostArtistId)
    ? props.hostArtistId
    : (props.hostOptions[0]?.value ?? null);

const form = useForm({
    host_artist_id: defaultHost,
    title: '',
    venue_id: null,
    location_name: '',
    city: '',
    state: '',
    country: '',
    location_note: '',
    date: '',
    start_time: '20:00',
    doors_time: '',
    // The poster is almost always listing a show where they are.
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    price: '',
    ticket_url: '',
    age_policy: null,
    description: '',
    lineup: [],
});

const submit = () => form.transform(showPayload).post(route('shows.store'));
</script>

<template>
    <Head title="List a show" />
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-center gap-5">
                            <q-avatar size="48px" color="primary" text-color="white">
                                <q-icon name="event" size="24px" />
                            </q-avatar>
                            <div class="text-h6 text-weight-bold text-grey-3">List a show</div>
                        </q-card-section>

                        <q-card-section>
                            <ShowFields
                                :form="form"
                                :host-options="hostOptions"
                                :age-policies="agePolicies"
                                :max-lineup="maxLineup"
                            />
                        </q-card-section>

                        <q-card-actions align="right" class="q-px-md q-pb-md q-pt-none">
                            <q-btn unelevated rounded color="primary" label="List show" padding="xs lg" :loading="form.processing" @click="submit" />
                        </q-card-actions>
                    </q-card>
                </div>
            </div>
        </q-page>
    </AuthenticatedLayout>
</template>

<style scoped>
.page-bg {
    background: #121212;
    min-height: 100vh;
}

.post-card {
    background: #1e1e1e;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
</style>
