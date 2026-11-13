<script setup>
import { computed } from 'vue';

// The artist profile form, shared by create and edit so the two can never
// drift apart. The parent owns the Inertia form object and the submit.
const props = defineProps({
    form: { type: Object, required: true },
    genreOptions: { type: Array, default: () => [] },
    artistTypes: { type: Array, default: () => [] },
    maxGenres: { type: Number, default: 3 },
    // [{ value, label }] from config('riff.wall.modes'). Empty on the
    // create form, where the wall has nothing on it yet.
    wallModes: { type: Array, default: () => [] },
});

const wallModes = computed(() => props.wallModes ?? []);

// Mirrors App\Services\MusicLink's host list closely enough to tell an
// artist, while they type, whether the link will play. The server is still
// the authority -- this only avoids a round trip to find out.
const MUSIC_HOSTS = [
    { match: /(^|\.)open\.spotify\.com$/, label: 'Spotify', playable: true },
    { match: /(^|\.)(youtube\.com|youtu\.be)$/, label: 'YouTube', playable: true },
    { match: /(^|\.)soundcloud\.com$/, label: 'SoundCloud', playable: true },
    { match: /(^|\.)music\.apple\.com$/, label: 'Apple Music', playable: true },
    { match: /(^|\.)bandcamp\.com$/, label: 'Bandcamp', playable: false },
];

const musicHint = computed(() => {
    const value = (props.form.music_url || '').trim();

    if (value === '') {
        return null;
    }

    let host;

    try {
        host = new URL(value).hostname.toLowerCase().replace(/^www\./, '');
    } catch {
        return { ok: false, text: 'That does not look like a full link yet.' };
    }

    const provider = MUSIC_HOSTS.find((candidate) => candidate.match.test(host));

    if (!provider) {
        return { ok: false, text: 'Riff can play Spotify, YouTube, SoundCloud and Apple Music links, and can link out to Bandcamp.' };
    }

    return provider.playable
        ? { ok: true, text: `${provider.label} detected — this will play on your profile.` }
        : { ok: true, text: `${provider.label} detected — Riff will link to it, but Bandcamp cannot be played inline.` };
});

const genreHint = computed(() => `Pick up to ${props.maxGenres}. These drive the map's genre filter.`);

// dark: Quasar's light-mode text and chips were dark grey on these dark
// fields -- values were hard to read and selected genre chips rendered
// white-on-white.
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
    <div class="q-gutter-md">
        <q-input
            v-model="form.name"
            label="Artist Name"
            v-bind="inputProps"
            :error="!!form.errors.name"
            :error-message="form.errors.name"
        />

        <q-select
            v-model="form.artist_type"
            :options="artistTypes"
            label="What kind of act is this?"
            option-value="value"
            option-label="label"
            emit-value
            map-options
            v-bind="inputProps"
            :error="!!form.errors.artist_type"
            :error-message="form.errors.artist_type"
        />

        <q-select
            v-model="form.genres"
            :options="genreOptions"
            label="Genres"
            option-value="id"
            option-label="name"
            emit-value
            map-options
            multiple
            use-chips
            :max-values="maxGenres"
            :hint="genreHint"
            v-bind="inputProps"
            :error="!!form.errors.genres"
            :error-message="form.errors.genres"
        >
            <!-- Quasar's default chip took the field's text colour as its
                 background and its text alike: a blank pill. -->
            <template #selected-item="{ opt, index, removeAtIndex, tabindex }">
                <q-chip
                    dense
                    removable
                    color="grey-8"
                    text-color="grey-2"
                    :tabindex="tabindex"
                    :remove-aria-label="`Remove ${opt.name}`"
                    class="q-my-xs"
                    @remove="removeAtIndex(index)"
                >
                    {{ opt.name }}
                </q-chip>
            </template>
        </q-select>

        <q-input
            v-model="form.biography"
            label="Short bio"
            type="textarea"
            autogrow
            hint="A couple of sentences is plenty — this is what a listener reads on the map."
            v-bind="inputProps"
            :error="!!form.errors.biography"
            :error-message="form.errors.biography"
        />

        <!-- Location -->
        <div class="text-subtitle2 text-weight-medium text-grey-5 q-pt-sm">Where you play</div>
        <q-banner dense class="bg-grey-9 text-grey-4 rounded-borders text-caption">
            <template #avatar>
                <q-icon name="place" color="primary" />
            </template>
            Your pin sits at the centre of the town you name here — never at a street address, and never at
            your live location. The town, state and country are public on your profile.
        </q-banner>

        <q-input
            v-model="form.hometown"
            label="Hometown"
            v-bind="inputProps"
            :error="!!form.errors.hometown"
            :error-message="form.errors.hometown"
        />
        <q-input
            v-model="form.stateOrProvince"
            label="State/Province"
            v-bind="inputProps"
            :error="!!form.errors.stateOrProvince"
            :error-message="form.errors.stateOrProvince"
        />
        <q-input
            v-model="form.country"
            label="Country"
            v-bind="inputProps"
            :error="!!form.errors.country"
            :error-message="form.errors.country"
        />

        <!-- text-grey-3: the label inherits the page's near-black body
             text; Quasar's dark mode only recolours the switch itself. -->
        <q-toggle
            dark
            v-model="form.show_on_map"
            color="primary"
            class="text-grey-3"
            label="Show this artist on the discovery map"
        />
        <div class="text-caption text-grey-6 q-mt-none">
            Turn this off to keep a public profile without appearing as a map pin.
        </div>

        <!-- Who may post on the wall. The artist's own call, which is why
             it is a setting here rather than a platform rule. -->
        <q-select
            v-if="wallModes.length"
            v-model="form.wall_mode"
            :options="wallModes"
            emit-value
            map-options
            label="Who can post on your wall"
            v-bind="inputProps"
            :error="!!form.errors.wall_mode"
            :error-message="form.errors.wall_mode"
        />
        <div v-if="wallModes.length" class="text-caption text-grey-6 q-mt-none">
            Fans — people who follow you — is the default. Opening it to anyone gets more
            conversation and more spam. Either way, you can always post as the band.
        </div>

        <!-- Music -->
        <div class="text-subtitle2 text-weight-medium text-grey-5 q-pt-sm">Something to listen to</div>
        <q-input
            v-model="form.music_url"
            label="Music link (track, album or playlist)"
            placeholder="https://open.spotify.com/track/..."
            v-bind="inputProps"
            :error="!!form.errors.music_url"
            :error-message="form.errors.music_url"
        />
        <div
            v-if="musicHint"
            class="text-caption"
            :class="musicHint.ok ? 'text-green-4' : 'text-orange-4'"
        >
            {{ musicHint.text }}
        </div>

        <!-- Links -->
        <div class="text-subtitle2 text-weight-medium text-grey-5 q-pt-sm">Links (all optional)</div>
        <q-input
            v-model="form.website"
            label="Website"
            v-bind="inputProps"
            :error="!!form.errors.website"
            :error-message="form.errors.website"
        />
        <q-input
            v-model="form.bandcamp_url"
            label="Bandcamp"
            v-bind="inputProps"
            :error="!!form.errors.bandcamp_url"
            :error-message="form.errors.bandcamp_url"
        />
        <q-input
            v-model="form.spotify_url"
            label="Spotify"
            v-bind="inputProps"
            :error="!!form.errors.spotify_url"
            :error-message="form.errors.spotify_url"
        />
        <q-input
            v-model="form.soundcloud_url"
            label="SoundCloud"
            v-bind="inputProps"
            :error="!!form.errors.soundcloud_url"
            :error-message="form.errors.soundcloud_url"
        />
        <q-input
            v-model="form.youtube_url"
            label="YouTube"
            v-bind="inputProps"
            :error="!!form.errors.youtube_url"
            :error-message="form.errors.youtube_url"
        />
        <q-input
            v-model="form.instagram_url"
            label="Instagram"
            v-bind="inputProps"
            :error="!!form.errors.instagram_url"
            :error-message="form.errors.instagram_url"
        />
        <q-input
            v-model="form.email"
            label="Contact email"
            hint="Shown publicly — use a booking address, not a personal one."
            v-bind="inputProps"
            :error="!!form.errors.email"
            :error-message="form.errors.email"
        />
    </div>
</template>

<style scoped>
.rounded-borders {
    border-radius: 10px;
}
</style>
