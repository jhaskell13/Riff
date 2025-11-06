<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    artist_id: 1,
    title: '',
    description: '',
    status: '',
});

const onSubmit = () => {
    form.post(route('stream.store'))
}
</script>

<template>
    <form
        class="flex flex-col gap-4 p-4 max-w-[500px]"
        @submit.prevent="onSubmit"    
    >
        <input v-model="form.title" type="text" placeholder="Title..">
        <input v-model="form.description" type="text" placeholder="Description...">
        <fieldset>
            <legend>Start now or schedule for later?</legend>
            <div>
                <input
                    v-model="form.status"
                    type="radio"
                    name="start_now"
                    value="live"
                    checked
                >
                <label for="start_now">Start Now</label>
            </div>
            <div>
                <input
                    v-model="form.status"
                    type="radio"
                    name="schedule"
                    value="scheduled"
                >
                <label for="schedule">Schedule</label>
            </div>
        </fieldset>
        <button class="border border-black rounded-md">
            Create Broadcast!
        </button>
    </form>
</template>