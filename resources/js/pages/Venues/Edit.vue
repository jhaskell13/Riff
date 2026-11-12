<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import VenueFields from '@/Components/VenueFields.vue';

const props = defineProps({
    venue: { type: Object, required: true },
    kinds: { type: Array, default: () => [] },
    agePolicies: { type: Array, default: () => [] },
    privateByDefault: { type: Array, default: () => [] },
});

const form = useForm({
    name: props.venue.name,
    kind: props.venue.kind,
    address_visibility: props.venue.address_visibility,
    street_address: props.venue.street_address ?? '',
    city: props.venue.city,
    state: props.venue.state,
    country: props.venue.country ?? '',
    age_policy: props.venue.age_policy,
    website: props.venue.website ?? '',
    description: props.venue.description ?? '',
});
</script>

<template>
    <Head :title="`Edit ${venue.name}`" />
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-center gap-5">
                            <q-avatar size="48px" color="primary" text-color="white">
                                <q-icon name="storefront" size="24px" />
                            </q-avatar>
                            <div class="text-h6 text-weight-bold text-grey-3">Edit {{ venue.name }}</div>
                        </q-card-section>

                        <q-card-section>
                            <VenueFields
                                :form="form"
                                :kinds="kinds"
                                :age-policies="agePolicies"
                                :private-by-default="privateByDefault"
                                keep-visibility
                            />
                        </q-card-section>

                        <q-card-actions align="right" class="q-px-md q-pb-md q-pt-none">
                            <Link :href="route('venues.show', venue.slug)" class="text-caption text-grey-6 q-mr-md">
                                Cancel
                            </Link>
                            <q-btn
                                unelevated
                                rounded
                                color="primary"
                                label="Save"
                                padding="xs lg"
                                :loading="form.processing"
                                @click="form.patch(route('venues.update', venue.id))"
                            />
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
