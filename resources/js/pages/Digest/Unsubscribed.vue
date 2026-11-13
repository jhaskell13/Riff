<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

// Reached from a signed link in an email, so the visitor may well not be
// signed in. Says plainly what happened and offers the finer control,
// without demanding an account first.
defineProps({
    email: { type: String, default: '' },
    settingsUrl: { type: String, default: '' },
});
</script>

<template>
    <Head title="Unsubscribed" />
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-8 col-md-6 col-lg-5 q-pa-md">
                    <q-card class="post-card q-mt-lg">
                        <q-card-section>
                            <div class="text-h6 text-weight-bold text-grey-3">That's done</div>
                            <p class="text-grey-5 q-mt-sm m-0">
                                We won't email <strong>{{ email }}</strong> the weekly digest any more.
                                Your account and everything in it is untouched — only the email stopped.
                            </p>
                        </q-card-section>
                        <q-card-actions class="q-px-md q-pb-md">
                            <q-btn
                                unelevated rounded no-caps color="primary"
                                label="Open Riff"
                                padding="xs lg"
                                :href="route('map.index')"
                                type="a"
                            />
                            <Link :href="settingsUrl" class="text-caption text-grey-5 q-ml-md">
                                Change what you hear about
                            </Link>
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
