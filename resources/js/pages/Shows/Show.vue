<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ReportDialog from '@/Components/ReportDialog.vue';

const props = defineProps({
    // ShowResource: confirmed acts only; times in the show's timezone.
    show: { type: Object, required: true },
    rsvp: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    canRsvp: { type: Boolean, default: false },
    isGuest: { type: Boolean, default: true },
});

const reporting = ref(false);
const pending = ref(false);
const listMe = ref(props.rsvp.mine?.isPublic ?? false);

const setRsvp = (status) => {
    if (pending.value) {
        return;
    }

    pending.value = true;
    const options = { preserveScroll: true, onFinish: () => (pending.value = false) };

    if (props.rsvp.mine?.status === status) {
        router.delete(route('shows.unrsvp', props.show.id), options);
    } else {
        router.post(route('shows.rsvp', props.show.id), { status, is_public: listMe.value }, options);
    }
};

const toggleListed = (value) => {
    listMe.value = value;

    if (props.rsvp.mine) {
        router.post(route('shows.rsvp', props.show.id), { status: props.rsvp.mine.status, is_public: value }, { preserveScroll: true });
    }
};

const metaDescription = computed(() => {
    const place = props.show.venue?.name ?? props.show.location?.name ?? '';
    const area = props.show.venue?.address ?? props.show.location?.area ?? '';

    return `${props.show.dateLabel}, ${props.show.timeLabel} · ${place}${area ? `, ${area}` : ''}`;
});
</script>

<template>
    <Head :title="show.title">
        <meta name="description" :content="metaDescription" />
        <meta property="og:title" :content="`${show.title} — Riff`" />
        <meta property="og:description" :content="metaDescription" />
        <meta property="og:type" content="website" />
    </Head>
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-banner v-if="show.isCancelled" class="bg-negative text-white rounded-borders q-mb-md">
                        <template #avatar><q-icon name="event_busy" /></template>
                        This show has been cancelled.
                    </q-banner>
                    <q-banner v-else-if="show.hasPassed" class="bg-grey-9 text-grey-4 rounded-borders q-mb-md">
                        <template #avatar><q-icon name="history" /></template>
                        This show has already happened.
                    </q-banner>

                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-start gap-4">
                            <q-avatar size="56px" color="primary" text-color="white">
                                <q-icon name="event" size="28px" />
                            </q-avatar>
                            <div class="leading-tight col">
                                <div class="text-h5 text-weight-bold text-grey-2">{{ show.title }}</div>
                                <div class="text-body2 text-grey-4 q-mt-xs">
                                    {{ show.dateLabel }} · {{ show.timeLabel }} {{ show.timezoneLabel }}
                                </div>
                                <div v-if="show.doorsLabel" class="text-caption text-grey-6">Doors {{ show.doorsLabel }}</div>
                                <div v-if="show.isImported" class="text-caption text-grey-6 q-mt-xs">
                                    <q-icon name="cloud_download" size="14px" /> Imported listing<template v-if="show.provider"> from {{ show.provider }}</template> — not confirmed by the artists
                                </div>
                            </div>
                            <div class="row items-center no-wrap">
                                <q-btn
                                    v-if="canManage"
                                    unelevated
                                    rounded
                                    color="primary"
                                    label="Edit"
                                    padding="xs lg"
                                    @click="router.visit(route('shows.edit', show.id))"
                                />
                                <q-btn
                                    v-if="!isGuest"
                                    flat dense round size="sm" icon="flag" color="grey-6"
                                    aria-label="Report this show"
                                    @click="reporting = true"
                                />
                            </div>
                        </q-card-section>

                        <q-separator color="grey-9" />

                        <!-- The bill -->
                        <q-card-section v-if="!show.hasOwnTitle || show.lineup.length > 1">
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-sm">The bill</div>
                            <div class="column gap-2">
                                <component
                                    :is="act.url ? Link : 'div'"
                                    v-for="act in show.lineup"
                                    :key="act.name"
                                    :href="act.url ?? undefined"
                                    class="row items-center gap-3 no-underline"
                                >
                                    <q-avatar size="32px" :color="act.imageUrl ? '' : 'grey-9'" text-color="grey-5">
                                        <img v-if="act.imageUrl" :src="act.imageUrl" :alt="`${act.name} photo`" />
                                        <q-icon v-else name="groups" size="18px" />
                                    </q-avatar>
                                    <span class="text-body2" :class="act.url ? 'text-grey-2' : 'text-grey-4'">{{ act.name }}</span>
                                </component>
                            </div>
                        </q-card-section>

                        <!-- Where -->
                        <q-card-section>
                            <div class="text-subtitle2 text-weight-medium text-grey-5 q-mb-xs">Where</div>
                            <template v-if="show.venue">
                                <Link :href="show.venue.url" class="text-body2 text-grey-2">{{ show.venue.name }}</Link>
                                <div class="text-caption text-grey-6">
                                    {{ show.venue.address }}
                                    <template v-if="show.venue.isPrivate"> · address shared by the hosts</template>
                                </div>
                            </template>
                            <template v-else-if="show.location">
                                <div class="text-body2 text-grey-2">{{ show.location.name }}</div>
                                <div class="text-caption text-grey-6">{{ show.location.area }}</div>
                                <div v-if="show.location.note" class="text-caption text-grey-4 q-mt-xs">
                                    <q-icon name="info" size="14px" /> {{ show.location.note }}
                                </div>
                            </template>
                        </q-card-section>

                        <q-card-section v-if="show.price || show.agePolicyLabel || show.ticketUrl" class="row items-center gap-2 q-pt-none">
                            <q-chip v-if="show.price" dense square color="grey-9" text-color="grey-3" icon="sell" class="q-ml-none">{{ show.price }}</q-chip>
                            <q-chip v-if="show.agePolicyLabel" dense square color="grey-9" text-color="grey-3" icon="badge">{{ show.agePolicyLabel }}</q-chip>
                            <q-btn
                                v-if="show.ticketUrl"
                                outline rounded dense no-caps
                                color="grey-4"
                                padding="xs md"
                                icon="confirmation_number"
                                label="Tickets"
                                :href="show.ticketUrl"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                            />
                        </q-card-section>

                        <q-card-section v-if="show.description">
                            <div class="text-body2 text-grey-3 whitespace-pre-line">{{ show.description }}</div>
                        </q-card-section>

                        <q-separator color="grey-9" />

                        <!-- Going -->
                        <q-card-section>
                            <div class="row items-center gap-3">
                                <div class="text-caption text-grey-5">
                                    {{ rsvp.going }} going · {{ rsvp.interested }} interested
                                </div>
                                <q-space />
                                <template v-if="canRsvp">
                                    <q-btn
                                        rounded no-caps unelevated
                                        :outline="rsvp.mine?.status !== 'interested'"
                                        :color="rsvp.mine?.status === 'interested' ? 'primary' : 'grey-5'"
                                        icon="star"
                                        label="Interested"
                                        padding="xs md"
                                        :loading="pending"
                                        :aria-pressed="rsvp.mine?.status === 'interested'"
                                        @click="setRsvp('interested')"
                                    />
                                    <q-btn
                                        rounded no-caps unelevated
                                        :outline="rsvp.mine?.status !== 'going'"
                                        :color="rsvp.mine?.status === 'going' ? 'primary' : 'grey-5'"
                                        icon="check"
                                        label="Going"
                                        padding="xs md"
                                        :loading="pending"
                                        :aria-pressed="rsvp.mine?.status === 'going'"
                                        @click="setRsvp('going')"
                                    />
                                </template>
                                <q-btn
                                    v-else-if="isGuest && !show.isCancelled && !show.hasPassed"
                                    unelevated rounded no-caps
                                    color="primary"
                                    label="Join Riff to say you're going"
                                    padding="xs md"
                                    :href="route('register', { intent: 'fan' })"
                                    type="a"
                                />
                            </div>
                            <q-toggle
                                v-if="canRsvp"
                                :model-value="listMe"
                                dark
                                dense
                                color="primary"
                                class="q-mt-sm text-caption text-grey-5"
                                label="Show my name to others who look at this show"
                                @update:model-value="toggleListed"
                            />
                            <div v-if="rsvp.publicGoing.length" class="text-caption text-grey-6 q-mt-sm">
                                Going: {{ rsvp.publicGoing.join(', ') }}
                            </div>
                        </q-card-section>

                        <q-card-section v-if="show.host" class="q-pt-none">
                            <div class="text-caption text-grey-7">
                                Listed by <Link :href="show.host.url" class="text-grey-5">{{ show.host.name }}</Link>
                            </div>
                        </q-card-section>
                    </q-card>
                </div>
            </div>

            <ReportDialog v-if="!isGuest" v-model="reporting" type="show" :id="show.id" :subject-name="show.title" />
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
