<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ShowList from '@/Components/ShowList.vue';

/**
 * A local scene: "Hartford punk". Who plays, what's on, and where — the
 * link someone texts a friend. Everything comes from SceneView, which uses
 * the map's own searches.
 */
const props = defineProps({
    scene: { type: Object, required: true },
    canFollow: { type: Boolean, default: false },
});

const title = computed(() => (props.scene.family
    ? `${props.scene.place.name} ${props.scene.family.label.toLowerCase()}`
    : `${props.scene.place.name}'s music scene`));

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

// What a shared link unfurls as.
const description = computed(() => [
    plural(props.scene.artistTotal, 'artist', 'artists'),
    `${plural(props.scene.shows.length, 'show', 'shows')} this month`,
    plural(props.scene.venues.length, 'venue', 'venues'),
    ...(props.scene.listings.length ? [`${plural(props.scene.listings.length, 'listing', 'listings')} looking for someone`] : []),
].join(', ') + ` around ${props.scene.place.label}, on Riff.`);

const isEmpty = computed(() => !props.scene.artistTotal && !props.scene.shows.length
    && !props.scene.venues.length && !props.scene.listings.length);

const allUrl = computed(() => route('scenes.show', props.scene.place.slug));
</script>

<template>
    <Head :title="title">
        <meta name="description" :content="description" />
        <meta property="og:title" :content="`${title} — Riff`" />
        <meta property="og:description" :content="description" />
        <meta property="og:type" content="website" />
    </Head>
    <AuthenticatedLayout>
        <q-page class="page-bg">
            <div class="mx-auto max-w-5xl px-4 py-6">
                <!-- Header -->
                <header class="mb-5">
                    <div class="text-xs tracking-wide text-riff-muted uppercase">
                        Within {{ scene.radius }} of {{ scene.place.label }}
                    </div>
                    <h1 class="m-0 mt-1 text-3xl leading-tight font-bold text-riff-text">{{ title }}</h1>
                    <p class="m-0 mt-2 text-sm text-riff-muted">{{ description }}</p>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <q-btn
                            unelevated rounded no-caps color="primary" icon="map"
                            label="See it on the map" padding="xs lg"
                            :href="scene.mapUrl" type="a"
                        />
                    </div>

                    <!-- Other scenes in the same place -->
                    <nav v-if="scene.families.length" class="mt-4 flex flex-wrap gap-2" aria-label="Scenes here">
                        <Link
                            :href="allUrl"
                            class="family-chip"
                            :class="{ 'family-chip--active': !scene.family }"
                            :aria-current="!scene.family ? 'page' : undefined"
                        >Everything</Link>
                        <Link
                            v-for="f in scene.families"
                            :key="f.slug"
                            :href="f.url"
                            class="family-chip"
                            :class="{ 'family-chip--active': scene.family?.slug === f.slug }"
                            :aria-current="scene.family?.slug === f.slug ? 'page' : undefined"
                        >{{ f.label }} <span class="family-chip__count">{{ f.count }}</span></Link>
                    </nav>
                </header>

                <!-- Empty: invite the scene in -->
                <section v-if="isEmpty" class="post-card p-8 text-center">
                    <q-icon name="travel_explore" size="36px" class="text-riff-muted" />
                    <p class="m-0 mt-3 text-base text-riff-text">
                        Nothing listed {{ scene.family ? `for ${scene.family.label.toLowerCase()} ` : '' }}around {{ scene.place.name }} yet.
                    </p>
                    <p class="m-0 mt-1 text-sm text-riff-muted">If you play here, put yourself — and your next show — on the map.</p>
                    <q-btn
                        unelevated rounded no-caps color="primary" padding="xs lg" class="mt-4"
                        :label="canFollow ? 'Add your artist' : 'Join Riff and add yourself'"
                        :href="canFollow ? route('artists.create') : route('register', { intent: 'artist' })"
                        type="a"
                    />
                </section>

                <div v-else class="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <!-- What's on -->
                    <section class="post-card p-4" aria-labelledby="scene-shows">
                        <h2 id="scene-shows" class="section-title">
                            <q-icon name="event" size="18px" class="text-riff-show" /> Coming up
                            <span class="section-title__note">next 30 days</span>
                        </h2>
                        <ShowList :shows="scene.shows" empty="No shows listed in the next month." />
                        <p v-if="scene.showsTruncated" class="m-0 mt-3 text-xs text-riff-muted">
                            Showing the soonest {{ scene.shows.length }} — <a :href="scene.mapUrl" class="text-riff-show">see the rest on the map</a>.
                        </p>
                    </section>

                    <!-- Where -->
                    <section class="post-card p-4" aria-labelledby="scene-venues">
                        <h2 id="scene-venues" class="section-title">
                            <q-icon name="storefront" size="18px" class="text-riff-venue" /> Where it happens
                        </h2>
                        <ul v-if="scene.venues.length" class="m-0 list-none space-y-2 p-0">
                            <li v-for="venue in scene.venues" :key="venue.id">
                                <Link :href="venue.url" class="row-link">
                                    <q-avatar size="36px" class="bg-riff-venue" text-color="white">
                                        <q-icon name="storefront" size="18px" />
                                    </q-avatar>
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-medium text-riff-text">{{ venue.name }}</div>
                                        <div class="truncate text-xs text-riff-muted">
                                            {{ venue.kindLabel }} · {{ venue.area }}<template v-if="venue.agePolicyLabel"> · {{ venue.agePolicyLabel }}</template>
                                        </div>
                                    </div>
                                    <span class="shrink-0 text-xs text-riff-muted">
                                        {{ venue.upcomingCount ? `${venue.upcomingCount} upcoming` : '' }}
                                    </span>
                                </Link>
                            </li>
                        </ul>
                        <p v-else class="m-0 text-sm text-riff-muted">No active venues listed here yet.</p>
                    </section>

                    <!-- Looking for -->
                    <section v-if="scene.listings.length" class="post-card p-4 lg:col-span-2" aria-labelledby="scene-listings">
                        <h2 id="scene-listings" class="section-title">
                            <q-icon name="campaign" size="18px" class="text-riff-listing" /> Looking for
                            <span class="section-title__note">bands, musicians and rooms</span>
                        </h2>
                        <ul class="m-0 grid list-none gap-2 p-0 sm:grid-cols-2">
                            <li v-for="listing in scene.listings" :key="listing.id">
                                <Link :href="listing.url" class="row-link items-start!">
                                    <q-avatar size="36px" class="bg-riff-listing" text-color="white">
                                        <q-icon :name="listing.icon" size="18px" />
                                    </q-avatar>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium text-riff-text">{{ listing.title }}</div>
                                        <div class="truncate text-xs text-riff-muted">
                                            {{ listing.typeLabel }}<template v-if="listing.instruments.length"> · {{ listing.instruments.join(', ') }}</template>
                                        </div>
                                        <div class="truncate text-xs text-riff-muted">{{ listing.area }} · {{ listing.postedLabel }}</div>
                                    </div>
                                </Link>
                            </li>
                        </ul>
                    </section>

                    <!-- Who plays -->
                    <section class="post-card p-4 lg:col-span-2" aria-labelledby="scene-artists">
                        <h2 id="scene-artists" class="section-title">
                            <q-icon name="groups" size="18px" class="text-riff-primary" /> Who plays
                            <span class="section-title__note">
                                {{ scene.artistTotal > scene.artists.length ? `the ${scene.artists.length} nearest of ${scene.artistTotal}` : plural(scene.artistTotal, 'artist', 'artists') }}
                            </span>
                        </h2>
                        <ul v-if="scene.artists.length" class="m-0 grid list-none gap-2 p-0 sm:grid-cols-2 lg:grid-cols-3">
                            <li v-for="artist in scene.artists" :key="artist.id">
                                <Link :href="artist.url" class="row-link">
                                    <q-avatar size="44px" :color="artist.imageUrl ? '' : 'grey-9'" text-color="grey-5">
                                        <img v-if="artist.imageUrl" :src="artist.imageUrl" :alt="`${artist.name} photo`" />
                                        <q-icon v-else name="music_note" size="20px" />
                                    </q-avatar>
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-medium text-riff-text">{{ artist.name }}</div>
                                        <div class="truncate text-xs text-riff-muted">{{ (artist.genres ?? []).join(' · ') || artist.typeLabel }}</div>
                                        <div class="truncate text-xs text-riff-muted">{{ artist.location }}</div>
                                    </div>
                                    <q-icon v-if="artist.hasMusic" name="play_circle" size="18px" class="shrink-0 text-riff-primary">
                                        <q-tooltip>Has music to play</q-tooltip>
                                    </q-icon>
                                </Link>
                            </li>
                        </ul>
                        <p v-else class="m-0 text-sm text-riff-muted">No artists listed here yet.</p>
                    </section>
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

.section-title {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 12px;
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-riff-text);
}

.section-title__note {
    margin-left: 4px;
    font-size: 0.75rem;
    font-weight: 400;
    color: var(--color-riff-muted);
}

.family-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border: 1px solid var(--color-riff-line);
    border-radius: 9999px;
    color: var(--color-riff-muted);
    font-size: 0.8rem;
    text-decoration: none;
}

.family-chip:hover {
    color: var(--color-riff-text);
    border-color: var(--color-riff-muted);
}

.family-chip--active {
    border-color: var(--color-riff-primary);
    background: color-mix(in srgb, var(--color-riff-primary) 18%, transparent);
    color: var(--color-riff-text);
}

.family-chip__count {
    font-size: 0.7rem;
    opacity: 0.75;
}

.row-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 8px;
    border-radius: 8px;
    text-decoration: none;
}

.row-link:hover {
    background: #262626;
}
</style>
