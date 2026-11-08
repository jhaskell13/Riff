<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    artists: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    artist_id: props.artists[0]?.id ?? null,
    title: '',
    description: '',
});

const onSubmit = () => {
    form.post(route('stream.store'));
};
</script>

<template>
    <div v-if="artists.length === 0" class="p-4 max-w-[500px]">
        <p>You need an artist identity before you can broadcast.</p>
        <a :href="route('artists.create')" class="underline">Create one here.</a>
    </div>
    <form
        v-else
        class="flex flex-col gap-4 p-4 max-w-[500px]"
        @submit.prevent="onSubmit"
    >
        <label for="artist_id">Broadcast as</label>
        <select id="artist_id" v-model="form.artist_id">
            <option v-for="artist in artists" :key="artist.id" :value="artist.id">
                {{ artist.name }}
            </option>
        </select>
        <div v-if="form.errors.artist_id" class="text-red-500 text-sm">{{ form.errors.artist_id }}</div>

        <input v-model="form.title" type="text" placeholder="Title..">
        <div v-if="form.errors.title" class="text-red-500 text-sm">{{ form.errors.title }}</div>

        <input v-model="form.description" type="text" placeholder="Description...">
        <div v-if="form.errors.description" class="text-red-500 text-sm">{{ form.errors.description }}</div>

        <button class="border border-black rounded-md" :disabled="form.processing">
            Create Broadcast!
        </button>
    </form>
</template>
