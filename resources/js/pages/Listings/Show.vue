<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ReportDialog from '@/Components/ReportDialog.vue';

const props = defineProps({
    // ListingResource
    listing: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    canRenew: { type: Boolean, default: false },
    canReply: { type: Boolean, default: false },
    hasReplied: { type: Boolean, default: false },
    replyCount: { type: Number, default: null },
    isGuest: { type: Boolean, default: true },
});

const reporting = ref(false);
const reply = useForm({ message: '', contact: '' });
// After sending, the page re-renders with hasReplied set.
const sent = computed(() => props.hasReplied);

const send = () => reply.post(route('listings.replies.store', props.listing.id), {
    preserveScroll: true,
    onSuccess: () => reply.reset(),
});

const renew = () => router.post(route('listings.renew', props.listing.id), {}, { preserveScroll: true });
const close = () => {
    if (confirm('Close this listing? It comes off the map and scene pages; its page stays as a record.')) {
        router.post(route('listings.close', props.listing.id), {}, { preserveScroll: true });
    }
};

const inputProps = {
    dark: true,
    rounded: true,
    outlined: true,
    'bg-color': 'grey-9',
    color: 'grey-4',
    'label-color': 'grey-5',
};
</script>

<template>
    <Head :title="listing.title">
        <meta name="description" :content="`${listing.typeLabel} · ${listing.area} — on Riff`" />
        <meta property="og:title" :content="`${listing.title} — Riff`" />
        <meta property="og:description" :content="`${listing.typeLabel} · ${listing.area}`" />
    </Head>
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-banner v-if="listing.isClosed" class="bg-grey-9 text-grey-4 rounded-borders q-mb-md">
                        <template #avatar><q-icon name="check_circle" /></template>
                        Closed by its poster — no longer looking.
                    </q-banner>
                    <q-banner v-else-if="!listing.isActive" class="bg-grey-9 text-grey-4 rounded-borders q-mb-md">
                        <template #avatar><q-icon name="schedule" /></template>
                        This listing has ended.
                    </q-banner>

                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-start gap-4">
                            <q-avatar size="56px" color="deep-purple-5" text-color="white">
                                <q-icon :name="listing.icon" size="28px" />
                            </q-avatar>
                            <div class="col leading-tight">
                                <div class="text-caption text-deep-purple-3 text-weight-medium">{{ listing.typeLabel }}</div>
                                <div class="text-h5 text-weight-bold text-grey-2">{{ listing.title }}</div>
                                <div class="text-caption text-grey-6 q-mt-xs">
                                    <template v-if="listing.postedBy.url">
                                        <Link :href="listing.postedBy.url" class="text-grey-4">{{ listing.postedBy.name }}</Link>
                                    </template>
                                    <template v-else>{{ listing.postedBy.name }}</template>
                                    · {{ listing.area }} · posted {{ listing.postedLabel }} · {{ listing.expiresLabel }}
                                </div>
                            </div>
                            <q-btn
                                v-if="!isGuest && !canManage"
                                flat dense round size="sm" icon="flag" color="grey-6"
                                aria-label="Report this listing"
                                @click="reporting = true"
                            />
                        </q-card-section>

                        <q-card-section v-if="listing.instruments.length || listing.genres.length" class="q-pt-none row gap-1">
                            <q-chip v-for="i in listing.instruments" :key="`i-${i}`" dense square color="deep-purple-9" text-color="deep-purple-1" icon="music_note" class="q-ml-none">{{ i }}</q-chip>
                            <q-chip v-for="g in listing.genres" :key="`g-${g}`" dense square color="grey-9" text-color="grey-3">{{ g }}</q-chip>
                        </q-card-section>

                        <q-card-section>
                            <div class="text-body2 text-grey-3 whitespace-pre-line">{{ listing.body }}</div>
                        </q-card-section>

                        <q-separator color="grey-9" />

                        <!-- The poster's side -->
                        <q-card-section v-if="canManage || canRenew">
                            <div class="text-caption text-grey-5 q-mb-sm">
                                {{ replyCount === 1 ? '1 reply' : `${replyCount ?? 0} replies` }} sent to your email so far.
                            </div>
                            <div class="row gap-2">
                                <q-btn v-if="canManage" outline rounded no-caps color="grey-4" icon="edit" label="Edit" padding="xs md" @click="router.visit(route('listings.edit', listing.id))" />
                                <q-btn v-if="canRenew" outline rounded no-caps color="deep-purple-3" icon="update" label="Renew for 30 days" padding="xs md" @click="renew" />
                                <q-btn v-if="canManage" flat rounded no-caps color="grey-5" icon="check" label="Found someone — close it" padding="xs md" @click="close" />
                            </div>
                        </q-card-section>

                        <!-- Replying -->
                        <q-card-section v-else-if="listing.isActive">
                            <template v-if="sent">
                                <div class="row items-center gap-2 text-body2 text-positive">
                                    <q-icon name="mark_email_read" /> Your reply was sent. They'll reach you using the contact you gave.
                                </div>
                            </template>
                            <template v-else-if="canReply">
                                <div class="text-subtitle2 text-weight-medium text-grey-3 q-mb-xs">Reply</div>
                                <p class="text-caption text-grey-6 m-0 q-mb-md">
                                    Your message is emailed to them. They won't see your email address — only the contact you type below.
                                </p>
                                <div class="q-gutter-md">
                                    <q-input v-model="reply.message" type="textarea" autogrow label="Message" counter maxlength="1000" v-bind="inputProps" :error="!!reply.errors.message" :error-message="reply.errors.message" />
                                    <q-input v-model="reply.contact" label="How they can reach you" hint="An email, a phone number, an Instagram handle — whatever you're happy to share." maxlength="200" v-bind="inputProps" :error="!!reply.errors.contact" :error-message="reply.errors.contact" />
                                </div>
                                <div class="row justify-end q-mt-md">
                                    <q-btn unelevated rounded no-caps color="primary" icon="send" label="Send reply" padding="xs lg" :loading="reply.processing" @click="send" />
                                </div>
                            </template>
                            <q-btn
                                v-else-if="isGuest"
                                unelevated rounded no-caps color="primary" label="Join Riff to reply" padding="xs lg"
                                :href="route('register', { intent: 'fan' })" type="a"
                            />
                        </q-card-section>
                    </q-card>
                </div>
            </div>

            <ReportDialog v-if="!isGuest" v-model="reporting" type="listing" :id="listing.id" :subject-name="listing.title" />
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
