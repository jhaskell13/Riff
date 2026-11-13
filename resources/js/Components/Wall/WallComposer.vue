<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Writing on an artist's wall. The voice is the point: a member has to see
// who they are about to speak as *before* they type, not discover it after
// — the roadmap's success signal is artists posting as their identity
// without being prompted.
const props = defineProps({
    artist: { type: Object, required: true },
    canPostAsArtist: { type: Boolean, default: false },
    canPostOnWall: { type: Boolean, default: false },
    maxLength: { type: Number, default: 5000 },
});

// Members default to the artist's voice on their own page: that is the
// common case, and the toggle below makes the other one one click away.
const asArtist = ref(props.canPostAsArtist);

const form = useForm({ content: '', as_artist: props.canPostAsArtist });

const voiceLabel = computed(() => (asArtist.value ? props.artist.name : 'yourself'));

const placeholder = computed(() => (asArtist.value
    ? `Tell your fans something as ${props.artist.name}…`
    : `Say something to ${props.artist.name} and their fans…`));

const setVoice = (value) => {
    asArtist.value = value;
    form.as_artist = value;
};

const submit = () => {
    if (!form.content.trim()) {
        return;
    }

    form.as_artist = asArtist.value;
    form.post(route('wall.posts.store', props.artist.id), {
        preserveScroll: true,
        onSuccess: () => form.reset('content'),
    });
};
</script>

<template>
    <div class="wall-composer column gap-2">
        <!-- Only shown to members, who are the only people with a choice. -->
        <div v-if="canPostAsArtist" class="row items-center gap-2">
            <span class="text-caption text-grey-6">Posting as</span>
            <q-btn-toggle
                :model-value="asArtist"
                :options="[
                    { label: artist.name, value: true },
                    { label: 'Yourself', value: false },
                ]"
                dense
                no-caps
                rounded
                unelevated
                toggle-color="primary"
                color="grey-9"
                text-color="grey-4"
                size="sm"
                @update:model-value="setVoice"
            />
        </div>

        <q-input
            v-model="form.content"
            type="textarea"
            dense
            outlined
            dark
            autogrow
            :placeholder="placeholder"
            :maxlength="maxLength"
            :error="Boolean(form.errors.content)"
            :error-message="form.errors.content"
            :aria-label="`Write a post as ${voiceLabel}`"
        />

        <div class="row items-center">
            <span class="text-caption text-grey-7">
                {{ asArtist ? 'Your fans will see this from the band.' : 'This shows your name.' }}
            </span>
            <q-space />
            <q-btn
                unelevated rounded no-caps
                color="primary"
                label="Post"
                padding="xs lg"
                :disable="!form.content.trim()"
                :loading="form.processing"
                @click="submit"
            />
        </div>
    </div>
</template>

<style scoped>
.wall-composer {
    padding: 12px;
    border: 1px solid #333;
    border-radius: 10px;
    background: #1a1a1a;
}
</style>
