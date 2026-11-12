<script setup>
import { computed, ref } from 'vue';

// The show form, shared by create and edit. The parent owns the Inertia
// form; this owns the pickers. `lineup` items are { artist_id, name,
// status? } in bill order — status only arrives on edit, so the host can
// see who still has to confirm.
const props = defineProps({
    form: { type: Object, required: true },
    hostOptions: { type: Array, default: () => [] },
    agePolicies: { type: Array, default: () => [] },
    maxLineup: { type: Number, default: 12 },
    // On edit the host is fixed and the venue arrives pre-resolved.
    hostFixed: { type: Boolean, default: false },
    initialVenue: { type: Object, default: null },
});

const inputProps = {
    dark: true,
    rounded: true,
    outlined: true,
    'bg-color': 'grey-9',
    color: 'grey-4',
    'label-color': 'grey-5',
};

// ---- Where: a venue, or a one-off location of the show's own -------------

const where = ref(props.form.venue_id ? 'venue' : (props.form.location_name ? 'oneoff' : 'venue'));

const setWhere = (value) => {
    where.value = value;

    // Exactly one of the two is sent; the server enforces it too.
    if (value === 'venue') {
        props.form.location_name = '';
        props.form.city = '';
        props.form.state = '';
        props.form.country = '';
        props.form.location_note = '';
    } else {
        props.form.venue_id = null;
        venueChoice.value = null;
    }
};

const venueChoice = ref(props.initialVenue
    ? { value: props.initialVenue.id, label: props.initialVenue.name, caption: props.initialVenue.area }
    : null);
const venueOptions = ref([]);

const lookup = async (url) => {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });

    return response.ok ? (await response.json()).data : [];
};

const searchVenues = (term, update, abort) => {
    if (term.length < 2) {
        abort();
        return;
    }

    lookup(route('search.venues', { q: term })).then((venues) => update(() => {
        venueOptions.value = venues.map((venue) => ({
            value: venue.id,
            label: venue.name,
            caption: `${venue.kindLabel} · ${venue.area}`,
        }));
    }));
};

const pickVenue = (option) => {
    venueChoice.value = option;
    props.form.venue_id = option?.value ?? null;
};

// ---- Lineup ---------------------------------------------------------------

const artistOptions = ref([]);
const freeName = ref('');

const searchArtists = (term, update, abort) => {
    if (term.length < 2) {
        abort();
        return;
    }

    lookup(route('search.artists', { q: term })).then((artists) => update(() => {
        const already = new Set(props.form.lineup.map((entry) => entry.artist_id).filter(Boolean));

        artistOptions.value = artists
            .filter((artist) => !already.has(artist.id))
            .map((artist) => ({ value: artist.id, label: artist.name, caption: artist.area }));
    }));
};

const full = computed(() => props.form.lineup.length >= props.maxLineup);

const addArtist = (option) => {
    if (option && !full.value) {
        props.form.lineup.push({ artist_id: option.value, name: option.label, status: null });
    }
};

const addFreeName = () => {
    const name = freeName.value.trim();

    if (name && !full.value) {
        props.form.lineup.push({ artist_id: null, name, status: null });
        freeName.value = '';
    }
};

const move = (index, step) => {
    const target = index + step;

    if (target < 0 || target >= props.form.lineup.length) {
        return;
    }

    const [entry] = props.form.lineup.splice(index, 1);
    props.form.lineup.splice(target, 0, entry);
};

const remove = (index) => props.form.lineup.splice(index, 1);

const isHost = (entry) => entry.artist_id !== null && entry.artist_id === props.form.host_artist_id;

// When the host changes on create, keep it on the bill (it must be).
const hostName = computed(() => props.hostOptions.find((o) => o.value === props.form.host_artist_id)?.label);

const ensureHostOnBill = () => {
    if (props.hostFixed || !props.form.host_artist_id) {
        return;
    }

    // Drop the entry auto-added for the previous host choice, then put
    // the new host first.
    props.form.lineup = props.form.lineup.filter((entry) => !entry.wasHost);

    if (!props.form.lineup.some((entry) => entry.artist_id === props.form.host_artist_id)) {
        props.form.lineup.unshift({ artist_id: props.form.host_artist_id, name: hostName.value, status: null, wasHost: true });
    }
};

ensureHostOnBill();

const statusLabel = {
    confirmed: { text: 'Confirmed', color: 'positive' },
    pending: { text: 'Waiting for them to confirm', color: 'orange-4' },
    declined: { text: 'Declined', color: 'negative' },
};

// ---- Timezone ---------------------------------------------------------------

const timezones = (() => {
    try {
        return Intl.supportedValuesOf('timeZone');
    } catch {
        return [props.form.timezone];
    }
})();
const timezoneOptions = ref(timezones);

const filterTimezones = (term, update) => update(() => {
    const needle = term.toLowerCase();
    timezoneOptions.value = timezones.filter((zone) => zone.toLowerCase().includes(needle));
});

// In venue mode the server's "need a place" errors land on the one-off
// fields, which aren't on screen; surface them on the venue picker.
const venueError = computed(() => props.form.errors.venue_id
    || (where.value === 'venue' && !props.form.venue_id
        && (props.form.errors.location_name || props.form.errors.city || props.form.errors.state)
        ? 'Pick a venue — or choose "Somewhere one-off".'
        : null));

const lineupError = computed(() => Object.entries(props.form.errors)
    .filter(([key]) => key === 'lineup' || key.startsWith('lineup.'))
    .map(([, message]) => message)[0]);
</script>

<template>
    <div class="q-gutter-md">
        <q-select
            v-if="!hostFixed"
            v-model="form.host_artist_id"
            :options="hostOptions"
            label="Who's listing this show?"
            hint="One of your artists. It's on the bill, and its members manage the listing."
            option-value="value"
            option-label="label"
            emit-value
            map-options
            v-bind="inputProps"
            :error="!!form.errors.host_artist_id"
            :error-message="form.errors.host_artist_id"
            @update:model-value="ensureHostOnBill"
        />

        <!-- Lineup -->
        <div class="text-subtitle2 text-weight-medium text-grey-5 q-pt-sm">The bill</div>
        <q-banner dense class="bg-grey-9 text-grey-4 rounded-borders text-caption">
            <template #avatar>
                <q-icon name="handshake" color="primary" />
            </template>
            Other Riff artists you add are asked to confirm, and appear on the show only once they do. Acts that
            aren't on Riff can be added by name.
        </q-banner>

        <div v-if="form.lineup.length" class="column gap-2">
            <div
                v-for="(entry, index) in form.lineup"
                :key="`${entry.artist_id ?? 'name'}-${entry.name}-${index}`"
                class="row no-wrap items-center gap-2 rounded-borders q-pa-sm bg-grey-10"
            >
                <div class="col leading-tight">
                    <div class="text-body2 text-grey-2">
                        {{ entry.name }}
                        <q-icon v-if="entry.artist_id" name="verified" size="14px" color="primary" class="q-ml-xs">
                            <q-tooltip>On Riff</q-tooltip>
                        </q-icon>
                    </div>
                    <div class="text-caption" :class="`text-${statusLabel[entry.status]?.color ?? 'grey-6'}`">
                        <template v-if="isHost(entry)">Hosting</template>
                        <template v-else-if="entry.status">{{ statusLabel[entry.status]?.text }}</template>
                        <template v-else-if="entry.artist_id">Will be asked to confirm</template>
                        <template v-else>Not on Riff</template>
                    </div>
                </div>
                <q-btn flat dense round size="sm" icon="arrow_upward" color="grey-5" :disable="index === 0" aria-label="Move up" @click="move(index, -1)" />
                <q-btn flat dense round size="sm" icon="arrow_downward" color="grey-5" :disable="index === form.lineup.length - 1" aria-label="Move down" @click="move(index, 1)" />
                <q-btn flat dense round size="sm" icon="close" color="grey-5" :disable="isHost(entry)" :aria-label="`Remove ${entry.name}`" @click="remove(index)" />
            </div>
        </div>
        <div v-if="lineupError" class="text-negative text-caption">{{ lineupError }}</div>

        <div v-if="!full" class="row q-col-gutter-md">
            <q-select
                class="col-12 col-sm-6"
                :model-value="null"
                :options="artistOptions"
                label="Add a Riff artist"
                use-input
                hide-selected
                fill-input
                input-debounce="250"
                v-bind="inputProps"
                @filter="searchArtists"
                @update:model-value="addArtist"
            >
                <template #option="scope">
                    <q-item v-bind="scope.itemProps" dark>
                        <q-item-section>
                            <q-item-label>{{ scope.opt.label }}</q-item-label>
                            <q-item-label caption>{{ scope.opt.caption }}</q-item-label>
                        </q-item-section>
                    </q-item>
                </template>
                <template #no-option>
                    <q-item dark><q-item-section class="text-grey-6">Type a name — or add them below if they're not on Riff.</q-item-section></q-item>
                </template>
            </q-select>
            <q-input
                v-model="freeName"
                class="col-12 col-sm-6"
                label="Add an act not on Riff"
                v-bind="inputProps"
                @keyup.enter="addFreeName"
            >
                <template #append>
                    <q-btn flat dense round icon="add" color="grey-4" aria-label="Add act" @click="addFreeName" />
                </template>
            </q-input>
        </div>

        <q-input
            v-model="form.title"
            label="Show title (optional)"
            hint="Leave blank and the bill is the title."
            v-bind="inputProps"
            :error="!!form.errors.title"
            :error-message="form.errors.title"
        />

        <!-- Where -->
        <div class="text-subtitle2 text-weight-medium text-grey-5 q-pt-sm">Where</div>
        <q-btn-toggle
            :model-value="where"
            no-caps
            rounded
            unelevated
            toggle-color="primary"
            color="grey-9"
            text-color="grey-4"
            :options="[
                { label: 'At a venue', value: 'venue' },
                { label: 'Somewhere one-off', value: 'oneoff' },
            ]"
            @update:model-value="setWhere"
        />

        <template v-if="where === 'venue'">
            <q-select
                :model-value="venueChoice"
                :options="venueOptions"
                label="Venue"
                use-input
                input-debounce="250"
                clearable
                v-bind="inputProps"
                :error="!!venueError"
                :error-message="venueError"
                @filter="searchVenues"
                @update:model-value="pickVenue"
            >
                <template #option="scope">
                    <q-item v-bind="scope.itemProps" dark>
                        <q-item-section>
                            <q-item-label>{{ scope.opt.label }}</q-item-label>
                            <q-item-label caption>{{ scope.opt.caption }}</q-item-label>
                        </q-item-section>
                    </q-item>
                </template>
                <template #no-option>
                    <q-item dark>
                        <q-item-section class="text-grey-6">
                            No venue by that name. If it hosts music regularly,
                            <a :href="route('venues.create')" target="_blank" class="text-primary">add it</a>;
                            if it's a one-off, choose "Somewhere one-off".
                        </q-item-section>
                    </q-item>
                </template>
            </q-select>
        </template>

        <template v-else>
            <q-banner dense class="bg-grey-9 text-grey-4 rounded-borders text-caption">
                <template #avatar>
                    <q-icon name="cottage" color="primary" />
                </template>
                For a backyard, a park, a house — anywhere that isn't a regular venue. It's placed at the town only,
                and leaves the map once the show is over. Don't put a street address in these fields.
            </q-banner>
            <q-input
                v-model="form.location_name"
                label="What to call the place"
                hint="e.g. &quot;Backyard on the west side&quot;"
                v-bind="inputProps"
                :error="!!form.errors.location_name"
                :error-message="form.errors.location_name"
            />
            <div class="row q-col-gutter-md">
                <q-input v-model="form.city" class="col-12 col-sm-5" label="Town or city" v-bind="inputProps" :error="!!form.errors.city" :error-message="form.errors.city" />
                <q-input v-model="form.state" class="col-6 col-sm-3" label="State" v-bind="inputProps" :error="!!form.errors.state" :error-message="form.errors.state" />
                <q-input v-model="form.country" class="col-6 col-sm-4" label="Country" v-bind="inputProps" :error="!!form.errors.country" :error-message="form.errors.country" />
            </div>
            <q-input
                v-model="form.location_note"
                label="How people find it (optional)"
                hint="&quot;Ask a punk&quot;, &quot;DM us for the address&quot;, &quot;TBA&quot;"
                v-bind="inputProps"
                :error="!!form.errors.location_note"
                :error-message="form.errors.location_note"
            />
        </template>

        <!-- When -->
        <div class="text-subtitle2 text-weight-medium text-grey-5 q-pt-sm">When</div>
        <div class="row q-col-gutter-md">
            <q-input v-model="form.date" type="date" class="col-12 col-sm-4" label="Date" stack-label v-bind="inputProps" :error="!!form.errors.date" :error-message="form.errors.date" />
            <q-input v-model="form.start_time" type="time" class="col-6 col-sm-4" label="Starts" stack-label v-bind="inputProps" :error="!!form.errors.start_time" :error-message="form.errors.start_time" />
            <q-input v-model="form.doors_time" type="time" class="col-6 col-sm-4" label="Doors (optional)" stack-label v-bind="inputProps" :error="!!form.errors.doors_time" :error-message="form.errors.doors_time" />
        </div>
        <q-select
            v-model="form.timezone"
            :options="timezoneOptions"
            label="Timezone"
            hint="Times show in the show's own timezone for everyone."
            use-input
            input-debounce="0"
            v-bind="inputProps"
            :error="!!form.errors.timezone"
            :error-message="form.errors.timezone"
            @filter="filterTimezones"
        />

        <!-- Details -->
        <div class="text-subtitle2 text-weight-medium text-grey-5 q-pt-sm">Details</div>
        <div class="row q-col-gutter-md">
            <q-input v-model="form.price" class="col-12 col-sm-5" label="Price (optional)" hint="&quot;$10&quot;, &quot;Free&quot;, &quot;PWYC&quot;" v-bind="inputProps" :error="!!form.errors.price" :error-message="form.errors.price" />
            <q-select
                v-model="form.age_policy"
                class="col-12 col-sm-7"
                :options="agePolicies"
                label="Age policy (optional)"
                hint="Leave blank to use the venue's."
                option-value="value"
                option-label="label"
                emit-value
                map-options
                clearable
                v-bind="inputProps"
                :error="!!form.errors.age_policy"
                :error-message="form.errors.age_policy"
            />
        </div>
        <q-input v-model="form.ticket_url" label="Ticket link (optional)" v-bind="inputProps" :error="!!form.errors.ticket_url" :error-message="form.errors.ticket_url" />
        <q-input
            v-model="form.description"
            label="Anything else (optional)"
            type="textarea"
            autogrow
            v-bind="inputProps"
            :error="!!form.errors.description"
            :error-message="form.errors.description"
        />
    </div>
</template>
