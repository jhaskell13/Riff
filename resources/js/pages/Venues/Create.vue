<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import VenueFields from '@/Components/VenueFields.vue';

defineProps({
    kinds: { type: Array, default: () => [] },
    agePolicies: { type: Array, default: () => [] },
    privateByDefault: { type: Array, default: () => [] },
});

const form = useForm({
    name: '',
    kind: 'club',
    address_visibility: 'public',
    street_address: '',
    city: '',
    state: '',
    country: '',
    age_policy: null,
    website: '',
    description: '',
});
</script>

<template>
    <Head title="Add a venue" />
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-center gap-5">
                            <q-avatar size="48px" color="primary" text-color="white">
                                <q-icon name="storefront" size="24px" />
                            </q-avatar>
                            <div class="text-h6 text-weight-bold text-grey-3">Add a venue</div>
                        </q-card-section>

                        <q-card-section>
                            <VenueFields
                                :form="form"
                                :kinds="kinds"
                                :age-policies="agePolicies"
                                :private-by-default="privateByDefault"
                            />
                        </q-card-section>

                        <q-card-actions align="right" class="q-px-md q-pb-md q-pt-none">
                            <q-btn
                                unelevated
                                rounded
                                color="primary"
                                label="Add venue"
                                padding="xs lg"
                                :loading="form.processing"
                                @click="form.post(route('venues.store'))"
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
