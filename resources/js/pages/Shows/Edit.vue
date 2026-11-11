<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ShowFields from '@/Components/ShowFields.vue';
import { showPayload } from '@/Components/showPayload';

const props = defineProps({
    show: { type: Object, required: true },
    hostOptions: { type: Array, default: () => [] },
    agePolicies: { type: Array, default: () => [] },
    maxLineup: { type: Number, default: 12 },
});

const form = useForm({
    host_artist_id: props.show.hostArtistId,
    title: props.show.title ?? '',
    venue_id: props.show.venue?.id ?? null,
    location_name: props.show.location_name ?? '',
    city: props.show.city ?? '',
    state: props.show.state ?? '',
    country: props.show.country ?? '',
    location_note: props.show.location_note ?? '',
    date: props.show.date,
    start_time: props.show.start_time,
    doors_time: props.show.doors_time ?? '',
    timezone: props.show.timezone,
    price: props.show.price ?? '',
    ticket_url: props.show.ticket_url ?? '',
    age_policy: props.show.age_policy,
    description: props.show.description ?? '',
    lineup: props.show.lineup.map((entry) => ({ ...entry })),
});

// The host is fixed after creation and is not part of an update.
const submit = () => form
    .transform((data) => {
        const { host_artist_id: _host, ...rest } = showPayload(data);

        return rest;
    })
    .patch(route('shows.update', props.show.id));

const cancelShow = () => {
    if (confirm('Cancel this show? Its page stays up and says it is cancelled, so anyone with the link finds out.')) {
        router.post(route('shows.cancel', props.show.id));
    }
};
</script>

<template>
    <Head title="Edit show" />
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-center gap-5">
                            <q-avatar size="48px" color="primary" text-color="white">
                                <q-icon name="event" size="24px" />
                            </q-avatar>
                            <div class="leading-tight">
                                <div class="text-h6 text-weight-bold text-grey-3">Edit show</div>
                                <div class="text-caption text-grey-6">Listed by {{ show.hostName }}</div>
                            </div>
                        </q-card-section>

                        <q-card-section>
                            <q-banner dense class="bg-grey-9 text-grey-4 rounded-borders text-caption q-mb-md">
                                <template #avatar>
                                    <q-icon name="info" color="orange-4" />
                                </template>
                                Changing the date, time or place asks the other artists on the bill to confirm again.
                            </q-banner>
                            <ShowFields
                                :form="form"
                                :host-options="hostOptions"
                                :age-policies="agePolicies"
                                :max-lineup="maxLineup"
                                :initial-venue="show.venue"
                                host-fixed
                            />
                        </q-card-section>

                        <q-card-actions class="q-px-md q-pb-md q-pt-none">
                            <q-btn flat rounded no-caps color="negative" label="Cancel the show" @click="cancelShow" />
                            <q-space />
                            <Link :href="route('shows.show', show.id)" class="text-caption text-grey-6 q-mr-md">Back</Link>
                            <q-btn unelevated rounded color="primary" label="Save" padding="xs lg" :loading="form.processing" @click="submit" />
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
