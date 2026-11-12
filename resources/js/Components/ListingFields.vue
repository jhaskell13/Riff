<script setup>
import { computed, watch } from 'vue';

// The listing form, shared by create and edit. The parent owns the form.
const props = defineProps({
    form: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    instruments: { type: Array, default: () => [] },
    genreOptions: { type: Array, default: () => [] },
    artistOptions: { type: Array, default: () => [] },
    // The poster's own town, for listings posted as a person.
    home: { type: Object, default: () => ({}) },
    // On edit the type is fixed: replies were made to that kind of post.
    typeFixed: { type: Boolean, default: false },
});

const inputProps = {
    dark: true,
    rounded: true,
    outlined: true,
    'bg-color': 'grey-9',
    color: 'grey-4',
    'label-color': 'grey-5',
};

const type = computed(() => props.types.find((t) => t.value === props.form.type));
// required | never | optional: whether this kind is posted as an artist.
const asArtist = computed(() => type.value?.asArtist ?? 'optional');

const fillTownFrom = (place) => {
    if (place?.city) {
        props.form.city = place.city;
        props.form.state = place.state ?? '';
        props.form.country = place.country ?? '';
    }
};

// Posting as a person never carries an artist; posting as an artist
// starts from that artist's town (and the poster can still change it).
watch(() => props.form.type, () => {
    if (asArtist.value === 'never') {
        props.form.artist_id = null;
        fillTownFrom(props.home);
    } else if (asArtist.value === 'required' && !props.form.artist_id && props.artistOptions.length) {
        props.form.artist_id = props.artistOptions[0].value;
    }
});

watch(() => props.form.artist_id, (id) => {
    fillTownFrom(props.artistOptions.find((a) => a.value === id) ?? (id ? null : props.home));
});

const needsArtistButHasNone = computed(() => asArtist.value === 'required' && !props.artistOptions.length);
</script>

<template>
    <div class="q-gutter-md">
        <q-select
            v-model="form.type"
            :options="types"
            :disable="typeFixed"
            label="What are you looking for?"
            option-value="value"
            option-label="label"
            emit-value
            map-options
            v-bind="inputProps"
            :error="!!form.errors.type"
            :error-message="form.errors.type"
        >
            <template #option="scope">
                <q-item v-bind="scope.itemProps" dark>
                    <q-item-section avatar><q-icon :name="scope.opt.icon" color="deep-purple-3" /></q-item-section>
                    <q-item-section>{{ scope.opt.label }}</q-item-section>
                </q-item>
            </template>
        </q-select>

        <q-banner v-if="needsArtistButHasNone" dense class="bg-grey-9 text-grey-4 rounded-borders text-caption">
            This kind of listing is posted as one of your artists, and you don't have one yet.
            <a :href="route('artists.create')" class="text-primary">Create your artist</a> first.
        </q-banner>

        <q-select
            v-if="asArtist !== 'never' && artistOptions.length"
            v-model="form.artist_id"
            :options="artistOptions"
            :label="asArtist === 'required' ? 'Posting as' : 'Posting as (optional)'"
            :clearable="asArtist === 'optional'"
            option-value="value"
            option-label="label"
            emit-value
            map-options
            v-bind="inputProps"
            :error="!!form.errors.artist_id"
            :error-message="form.errors.artist_id"
        />

        <q-input
            v-model="form.title"
            label="Headline"
            hint="e.g. &quot;Drummer wanted — fast hardcore, New Britain&quot;"
            counter
            maxlength="120"
            v-bind="inputProps"
            :error="!!form.errors.title"
            :error-message="form.errors.title"
        />

        <q-input
            v-model="form.body"
            label="Details"
            type="textarea"
            autogrow
            counter
            maxlength="1000"
            hint="What you play, what you're after, how often you rehearse. Don't put your address or phone number here — replies come to you privately."
            v-bind="inputProps"
            :error="!!form.errors.body"
            :error-message="form.errors.body"
        />

        <q-select
            v-model="form.instruments"
            :options="instruments"
            label="Instruments (optional)"
            option-value="value"
            option-label="label"
            emit-value
            map-options
            multiple
            use-chips
            :max-values="6"
            v-bind="inputProps"
            :error="!!form.errors.instruments"
            :error-message="form.errors.instruments"
        >
            <template #selected-item="{ opt, index, removeAtIndex, tabindex }">
                <q-chip dense removable color="grey-8" text-color="grey-2" :tabindex="tabindex" :remove-aria-label="`Remove ${opt.label}`" class="q-my-xs" @remove="removeAtIndex(index)">{{ opt.label }}</q-chip>
            </template>
        </q-select>

        <q-select
            v-model="form.genres"
            :options="genreOptions"
            label="Genres (optional)"
            hint="Up to 3 — these put the listing in the right scene."
            option-value="id"
            option-label="name"
            emit-value
            map-options
            multiple
            use-chips
            :max-values="3"
            v-bind="inputProps"
            :error="!!form.errors.genres"
            :error-message="form.errors.genres"
        >
            <template #selected-item="{ opt, index, removeAtIndex, tabindex }">
                <q-chip dense removable color="grey-8" text-color="grey-2" :tabindex="tabindex" :remove-aria-label="`Remove ${opt.name}`" class="q-my-xs" @remove="removeAtIndex(index)">{{ opt.name }}</q-chip>
            </template>
        </q-select>

        <div class="text-subtitle2 text-weight-medium text-grey-5 q-pt-sm">Where</div>
        <q-banner dense class="bg-grey-9 text-grey-4 rounded-borders text-caption">
            <template #avatar><q-icon name="place" color="primary" /></template>
            Shown publicly, and only as a town — the listing's pin sits at the town's centre.
        </q-banner>
        <div class="row q-col-gutter-md">
            <q-input v-model="form.city" class="col-12 col-sm-5" label="Town or city" v-bind="inputProps" :error="!!form.errors.city" :error-message="form.errors.city" />
            <q-input v-model="form.state" class="col-6 col-sm-3" label="State" v-bind="inputProps" :error="!!form.errors.state" :error-message="form.errors.state" />
            <q-input v-model="form.country" class="col-6 col-sm-4" label="Country" v-bind="inputProps" :error="!!form.errors.country" :error-message="form.errors.country" />
        </div>
    </div>
</template>
