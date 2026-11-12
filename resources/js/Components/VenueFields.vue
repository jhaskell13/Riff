<script setup>
import { computed, watch } from 'vue';

// The venue form, shared by create and edit so the two cannot drift. The
// parent owns the Inertia form object and the submit.
const props = defineProps({
    form: { type: Object, required: true },
    kinds: { type: Array, default: () => [] },
    agePolicies: { type: Array, default: () => [] },
    // Kinds whose address defaults to private (config riff.venues).
    privateByDefault: { type: Array, default: () => [] },
    // On edit, the submitter's own choice must never be flipped by
    // re-picking a kind.
    keepVisibility: { type: Boolean, default: false },
});

const isPrivate = computed(() => props.form.address_visibility === 'private');

// A DIY space usually wants its address kept with the hosts. Suggest it
// when the kind changes; the submitter can still choose otherwise.
watch(() => props.form.kind, (kind) => {
    if (props.keepVisibility) {
        return;
    }

    props.form.address_visibility = props.privateByDefault.includes(kind) ? 'private' : 'public';
});

const visibilityOptions = [
    { value: 'public', label: 'Public address', hint: 'Pinned at the street address. Right for clubs, bars, halls and shops.' },
    { value: 'private', label: 'Share the address privately', hint: 'Riff stores no street at all and pins the town. Fans get the address from the hosts.' },
];

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
        <!-- The one thing a venue is not: the line between a venue and a
             one-off show location is the whole design of this form. -->
        <q-banner dense class="bg-grey-9 text-grey-4 rounded-borders text-caption">
            <template #avatar>
                <q-icon name="storefront" color="primary" />
            </template>
            Venues are places that host music <strong>regularly</strong> — clubs, bars with live music, small
            halls, record stores, named DIY spaces with a running calendar. A one-off backyard, park or house
            show doesn't need a venue: it gets its own location on the show itself.
        </q-banner>

        <q-input
            v-model="form.name"
            label="Venue name"
            v-bind="inputProps"
            :error="!!form.errors.name"
            :error-message="form.errors.name"
        />

        <q-select
            v-model="form.kind"
            :options="kinds"
            label="What kind of place is it?"
            option-value="value"
            option-label="label"
            emit-value
            map-options
            v-bind="inputProps"
            :error="!!form.errors.kind"
            :error-message="form.errors.kind"
        />

        <q-select
            v-model="form.age_policy"
            :options="agePolicies"
            label="Age policy (optional)"
            option-value="value"
            option-label="label"
            emit-value
            map-options
            clearable
            hint="All-ages is often the first thing a younger fan needs to know."
            v-bind="inputProps"
            :error="!!form.errors.age_policy"
            :error-message="form.errors.age_policy"
        />

        <!-- Location -->
        <div class="text-subtitle2 text-weight-medium text-grey-5 q-pt-sm">Where it is</div>

        <q-option-group
            v-model="form.address_visibility"
            :options="visibilityOptions"
            type="radio"
            dark
            color="primary"
        >
            <template #label="opt">
                <div class="leading-tight q-py-xs">
                    <div class="text-grey-2">{{ opt.label }}</div>
                    <div class="text-caption text-grey-6">{{ opt.hint }}</div>
                </div>
            </template>
        </q-option-group>
        <div v-if="form.errors.address_visibility" class="text-negative text-caption">
            {{ form.errors.address_visibility }}
        </div>

        <q-input
            v-if="!isPrivate"
            v-model="form.street_address"
            label="Street address"
            v-bind="inputProps"
            :error="!!form.errors.street_address"
            :error-message="form.errors.street_address"
        />

        <div class="row q-col-gutter-md">
            <q-input
                v-model="form.city"
                class="col-12 col-sm-5"
                label="Town or city"
                v-bind="inputProps"
                :error="!!form.errors.city"
                :error-message="form.errors.city"
            />
            <q-input
                v-model="form.state"
                class="col-6 col-sm-3"
                label="State"
                v-bind="inputProps"
                :error="!!form.errors.state"
                :error-message="form.errors.state"
            />
            <q-input
                v-model="form.country"
                class="col-6 col-sm-4"
                label="Country"
                v-bind="inputProps"
                :error="!!form.errors.country"
                :error-message="form.errors.country"
            />
        </div>

        <q-input
            v-model="form.website"
            label="Website or calendar link (optional)"
            v-bind="inputProps"
            :error="!!form.errors.website"
            :error-message="form.errors.website"
        />

        <q-input
            v-model="form.description"
            label="About the place (optional)"
            type="textarea"
            autogrow
            hint="Capacity, the room, what kind of nights it runs."
            v-bind="inputProps"
            :error="!!form.errors.description"
            :error-message="form.errors.description"
        />
    </div>
</template>
