<script setup>
import { useForm, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ShowList from '@/Components/ShowList.vue';

const props = defineProps({
    artists: {
        type: Array,
        default: () => [],
    },
    archivedArtists: {
        type: Array,
        default: () => [],
    },
    // Venues this user added; they edit them until a venue is claimed
    // (Phase 4).
    // Pending lineup slots on other artists' bills, for one of this
    // user's artists to confirm or decline.
    showInvites: {
        type: Array,
        default: () => [],
    },
    // Upcoming shows this user's artists host.
    hostedShows: {
        type: Array,
        default: () => [],
    },
    venues: {
        type: Array,
        default: () => [],
    },
});

// Artists carry a genre list now rather than a single string.
const genreNames = (artist) => (artist.genres ?? []).map((genre) => genre.name).join(', ');

const answering = ref(null);

const answer = (invite, decision) => {
    answering.value = invite.id;
    router.post(route(`lineup.${decision}`, invite.id), {}, {
        preserveScroll: true,
        onFinish: () => (answering.value = null),
    });
};

const restore = (artist) => {
    if (confirm(`Restore ${artist.name}? The identity becomes visible on Riff again.`)) {
        router.post(route('artists.restore', artist.id));
    }
};

const archive = (artist) => {
    if (confirm(`Archive ${artist.name}? The identity is hidden from Riff but can be restored later.`)) {
        router.delete(route('artists.destroy', artist.id));
    }
};
</script>

<template>
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="row justify-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 q-pa-md">
                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-center gap-5">
                            <q-avatar class="self-start q-mt-sm" size="48px" color="primary" text-color="white">
                                <q-icon name="queue_music" size="24px" />
                            </q-avatar>
                            <div class="leading-tight">
                                <div class="text-h6 text-weight-bold text-grey-3">
                                    Your Artist Identities
                                </div>
                            </div>
                            <q-card-actions class="ml-auto">
                                <q-btn
                                    v-if="artists.length > 0"
                                    unelevated
                                    rounded
                                    color="primary"
                                    label="Create/Join Artist"
                                    padding="xs lg"
                                    @click="router.visit(route('artists.create'))"
                                />
                            </q-card-actions>
                        </q-card-section>
                        <q-separator color="grey-9" />
                        <q-card-section v-if="artists.length > 0">
                            <div
                                v-for="artist in artists"
                                :key="artist.id"
                                class="flex mt-5 first:mt-0"
                            >
                                <q-avatar size="48px" :color="artist.image_url ? '' : 'primary'" text-color="white">
                                    <img v-if="artist.image_url" :src="artist.image_url" />
                                    <q-icon v-else name="groups" />
                                </q-avatar>
                                <div class="col q-ml-md">
                                    <div class="text-subtitle1 text-weight-medium text-grey-2">{{ artist.name }}</div>
                                    <div class="text-caption text-grey-6">
                                        <span>
                                            {{ artist.address }} • {{ genreNames(artist) }} • Members:
                                        </span>
                                        <span
                                            v-for="(member, index) in artist.members"
                                            :key="member.name"
                                        >
                                            {{ member.name }}{{ index < artist.members.length - 1 ? ', ' : '' }}
                                        </span>
                                    </div>
                                </div>
                                <div class="my-auto flex gap-2">
                                    <q-btn
                                        round
                                        color="primary"
                                        icon="visibility"
                                        size="xs"
                                        @click="router.visit(route('artists.show', artist.slug))"
                                    >
                                        <q-tooltip>View profile</q-tooltip>
                                    </q-btn>
                                    <q-btn
                                        round
                                        color="primary"
                                        icon="event"
                                        size="xs"
                                        @click="router.visit(route('shows.create', { artist: artist.id }))"
                                    >
                                        <q-tooltip>List a show</q-tooltip>
                                    </q-btn>
                                    <q-btn
                                        round
                                        color="primary"
                                        icon="edit"
                                        size="xs"
                                        @click="router.visit(route('artists.edit', artist.id))"
                                    >
                                        <q-tooltip>Edit</q-tooltip>
                                    </q-btn>
                                    <q-btn
                                        round
                                        color="negative"
                                        icon="archive"
                                        size="xs"
                                        @click="archive(artist)"
                                    >
                                        <q-tooltip>Archive</q-tooltip>
                                    </q-btn>
                                </div>
                                <q-separator color="grey-9" />
                            </div>
                        </q-card-section>
                        <q-card-section v-else class="flex flex-col">
                            <div class="text-h5 mx-auto mt-8 text-weight-bold text-grey-3">
                                You are not an artist... yet!
                            </div>
                            <q-btn
                                unelevated
                                rounded
                                color="primary"
                                label="Create/Join Artist"
                                padding="xs lg"
                                class="mt-2 m-auto"
                                @click="router.visit(route('artists.create'))"
                            />
                        </q-card-section>
                    </q-card>

                    <q-card v-if="artists.length > 0" class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-center gap-3">
                            <div class="text-subtitle1 text-weight-medium text-grey-3">Shows</div>
                            <q-btn
                                unelevated
                                rounded
                                color="primary"
                                icon="add"
                                label="List a show"
                                padding="xs lg"
                                class="ml-auto"
                                @click="router.visit(route('shows.create'))"
                            />
                        </q-card-section>
                        <template v-if="showInvites.length">
                            <q-separator color="grey-9" />
                            <q-card-section>
                                <div class="text-subtitle2 text-weight-medium text-orange-4 q-mb-xs">Waiting for you to confirm</div>
                                <p class="text-caption text-grey-6 m-0 q-mb-sm">
                                    You're named on these bills. You appear on a show only once you confirm.
                                </p>
                                <div v-for="invite in showInvites" :key="invite.id" class="q-mb-md">
                                    <div class="text-caption text-grey-5 q-mb-xs">
                                        {{ invite.hostName }} added <strong>{{ invite.artistName }}</strong>
                                    </div>
                                    <ShowList :shows="[invite.show]" />
                                    <div class="row justify-end gap-2 q-mt-xs">
                                        <q-btn flat rounded dense no-caps color="grey-5" label="Decline" :loading="answering === invite.id" @click="answer(invite, 'decline')" />
                                        <q-btn unelevated rounded dense no-caps color="primary" label="Confirm" padding="xs md" :loading="answering === invite.id" @click="answer(invite, 'confirm')" />
                                    </div>
                                </div>
                            </q-card-section>
                        </template>
                        <q-separator color="grey-9" />
                        <q-card-section>
                            <div class="text-subtitle2 text-weight-medium text-grey-4 q-mb-sm">Your upcoming shows</div>
                            <ShowList :shows="hostedShows" empty="No shows listed yet." />
                        </q-card-section>
                    </q-card>

                    <q-card class="post-card q-pb-md q-mb-xl">
                        <q-card-section class="row flex-nowrap! items-center gap-3">
                            <div class="leading-tight">
                                <div class="text-subtitle1 text-weight-medium text-grey-3">Venues you added</div>
                                <p class="text-caption text-grey-6 m-0">
                                    Places that host music regularly. One-off shows don't need one.
                                </p>
                            </div>
                            <q-btn
                                unelevated
                                rounded
                                color="primary"
                                icon="add"
                                label="Add a venue"
                                padding="xs lg"
                                class="ml-auto"
                                @click="router.visit(route('venues.create'))"
                            />
                        </q-card-section>
                        <template v-if="venues.length > 0">
                            <q-separator color="grey-9" />
                            <q-card-section>
                                <div
                                    v-for="venue in venues"
                                    :key="venue.id"
                                    class="flex items-center mt-4 first:mt-0"
                                >
                                    <q-avatar size="36px" color="grey-9" text-color="grey-5">
                                        <q-icon name="storefront" size="20px" />
                                    </q-avatar>
                                    <div class="col q-ml-md">
                                        <div class="text-subtitle2 text-grey-2">{{ venue.name }}</div>
                                        <div class="text-caption text-grey-6">{{ venue.kindLabel }} • {{ venue.area }}</div>
                                    </div>
                                    <div class="flex gap-2">
                                        <q-btn round color="primary" icon="visibility" size="xs" @click="router.visit(route('venues.show', venue.slug))">
                                            <q-tooltip>View venue</q-tooltip>
                                        </q-btn>
                                        <q-btn round color="primary" icon="edit" size="xs" @click="router.visit(route('venues.edit', venue.id))">
                                            <q-tooltip>Edit</q-tooltip>
                                        </q-btn>
                                    </div>
                                </div>
                            </q-card-section>
                        </template>
                    </q-card>

                    <q-card v-if="archivedArtists.length > 0" class="post-card q-pb-md q-mb-xl">
                        <q-card-section>
                            <div class="text-subtitle1 text-weight-medium text-grey-4">Archived artists</div>
                            <p class="text-caption text-grey-7 m-0">
                                Hidden from the map and profiles. Restore one to bring it back.
                            </p>
                        </q-card-section>
                        <q-separator color="grey-9" />
                        <q-card-section>
                            <div
                                v-for="artist in archivedArtists"
                                :key="artist.id"
                                class="flex items-center mt-4 first:mt-0"
                            >
                                <div class="col">
                                    <div class="text-subtitle2 text-grey-4">{{ artist.name }}</div>
                                    <div class="text-caption text-grey-7">{{ artist.address }} • {{ genreNames(artist) }}</div>
                                </div>
                                <q-btn
                                    round
                                    color="primary"
                                    icon="unarchive"
                                    size="xs"
                                    @click="restore(artist)"
                                >
                                    <q-tooltip>Restore</q-tooltip>
                                </q-btn>
                            </div>
                        </q-card-section>
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

.rounded-borders {
  border-radius: 10px;
}
</style>
