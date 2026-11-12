<script setup>
import { computed } from 'vue';

// Editing the whole "Support this artist" section at once: the common
// edits are adding, removing and reordering, and each of those would be
// several requests if every link saved on its own.
//
// Existing rows carry their id so the server can match them and keep their
// click counts through an edit. The id is a hint, not a permission: the
// server ignores any id that isn't this artist's.
const props = defineProps({
    // An Inertia useForm with a `links` array.
    form: { type: Object, required: true },
    supportTypes: { type: Array, default: () => [] },
    maxLinks: { type: Number, default: 6 },
    maxLabel: { type: Number, default: 40 },
});

const typeOptions = computed(() => props.supportTypes.map((type) => ({
    label: type.label,
    value: type.value,
    icon: type.icon,
})));

const typeFor = (value) => props.supportTypes.find((type) => type.value === value) ?? null;

// What this type will accept, so the artist reads it before submitting
// rather than after the server says no.
const hintFor = (link) => {
    const type = typeFor(link.type);

    if (!type) {
        return null;
    }

    return type.hosts?.length
        ? `${type.hint ?? ''} Must be a link on ${type.hosts.join(', ')}.`.trim()
        : type.hint ?? null;
};

const atLimit = computed(() => props.form.links.length >= props.maxLinks);

const add = () => {
    if (atLimit.value) {
        return;
    }

    props.form.links.push({ id: null, type: 'bandcamp', url: '', label: '' });
};

const remove = (index) => {
    props.form.links.splice(index, 1);
};

const move = (index, delta) => {
    const target = index + delta;

    if (target < 0 || target >= props.form.links.length) {
        return;
    }

    const links = props.form.links;
    [links[index], links[target]] = [links[target], links[index]];
};

// Errors come back keyed by position (links.0.url), which is also how the
// server reads the array, so an error lands on the row it belongs to.
const errorFor = (index, field) => props.form.errors?.[`links.${index}.${field}`] ?? null;
</script>

<template>
    <div class="column gap-4">
        <p class="text-caption text-grey-6 m-0">
            Links to where you already get paid — Bandcamp, your own store, merch, a tip jar. Riff sends people
            there and takes no cut. Nothing is sold through Riff.
        </p>

        <div
            v-for="(link, index) in props.form.links"
            :key="index"
            class="support-row column gap-2"
        >
            <div class="row items-center no-wrap gap-2">
                <q-select
                    v-model="link.type"
                    :options="typeOptions"
                    emit-value
                    map-options
                    dense
                    outlined
                    dark
                    options-dark
                    label="Kind"
                    label-color="grey-5"
                    class="col-grow"
                    :error="Boolean(errorFor(index, 'type'))"
                    :error-message="errorFor(index, 'type')"
                >
                    <template #prepend>
                        <q-icon :name="typeFor(link.type)?.icon ?? 'link'" size="18px" color="teal-3" />
                    </template>
                </q-select>

                <q-btn
                    flat dense round
                    color="grey-6"
                    icon="arrow_upward"
                    :disable="index === 0"
                    aria-label="Move this link up"
                    @click="move(index, -1)"
                />
                <q-btn
                    flat dense round
                    color="grey-6"
                    icon="arrow_downward"
                    :disable="index === props.form.links.length - 1"
                    aria-label="Move this link down"
                    @click="move(index, 1)"
                />
                <q-btn
                    flat dense round
                    color="negative"
                    icon="close"
                    :aria-label="`Remove this ${typeFor(link.type)?.label ?? 'support'} link`"
                    @click="remove(index)"
                />
            </div>

            <q-input
                v-model="link.url"
                dense
                outlined
                dark
                label="Link"
                label-color="grey-5"
                placeholder="https://"
                :error="Boolean(errorFor(index, 'url'))"
                :error-message="errorFor(index, 'url')"
                :hint="hintFor(link)"
            />

            <q-input
                v-model="link.label"
                dense
                outlined
                dark
                label="Button text (optional)"
                label-color="grey-5"
                :maxlength="props.maxLabel"
                :placeholder="typeFor(link.type)?.label ?? ''"
                :error="Boolean(errorFor(index, 'label'))"
                :error-message="errorFor(index, 'label')"
            />

            <!-- The artist's own number. It is never published: a public
                 click count would be a popularity ranking. -->
            <div v-if="typeof link.clicks === 'number'" class="text-caption text-grey-7">
                {{ link.clicks }} {{ link.clicks === 1 ? 'click' : 'clicks' }} from Riff
            </div>
        </div>

        <div v-if="props.form.errors?.links" class="text-negative text-caption">
            {{ props.form.errors.links }}
        </div>

        <div class="row items-center gap-3">
            <q-btn
                flat dense rounded no-caps
                color="teal-3"
                icon="add"
                label="Add a link"
                :disable="atLimit"
                @click="add"
            />
            <span v-if="atLimit" class="text-caption text-grey-6">
                That's the most you can show ({{ props.maxLinks }}).
            </span>
        </div>
    </div>
</template>

<style scoped>
.support-row {
    padding: 12px;
    border: 1px solid #333;
    border-radius: 8px;
    background: #1a1a1a;
}
</style>
