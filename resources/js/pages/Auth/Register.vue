<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

/**
 * Registration, reached from one of the map's two paths. The intent only
 * changes what this page says and where the account lands afterwards --
 * the account itself is the same either way, and it can be switched here
 * without losing anything already typed.
 */
const props = defineProps({
    intent: { type: String, default: null },
});

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    city: '',
    state: '',
    country: '',
    intent: props.intent ?? '',
});

const copy = {
    artist: {
        title: 'Get your music on the map',
        lead: 'Create your account, then add your artist profile: name, genres, a photo, and a track. People nearby will find you on the map.',
    },
    fan: {
        title: 'Find music made near you',
        lead: 'Create an account to follow local artists and keep their releases and shows in one place.',
    },
    none: {
        title: 'Join Riff',
        lead: 'Riff maps independent artists by the area they choose, so you can find music made nearby.',
    },
};

const heading = computed(() => copy[form.intent] ?? copy.none);
const otherPath = computed(() => (form.intent === 'artist' ? 'fan' : 'artist'));

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Register" />

        <div class="mb-6">
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ heading.title }}
            </h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ heading.lead }}
            </p>
        </div>

        <!-- Which path, when the map did not already say. Switching is a
             local change: nothing typed so far is lost. -->
        <fieldset v-if="!form.intent" class="mb-6">
            <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">
                What brings you here?
            </legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <button
                    type="button"
                    class="rounded-md border border-gray-300 px-3 py-2 text-left text-sm text-gray-700 hover:border-indigo-500 focus:ring-2 focus:ring-indigo-500 focus:outline-hidden dark:border-gray-600 dark:text-gray-300"
                    @click="form.intent = 'fan'"
                >
                    I'm here for the music
                </button>
                <button
                    type="button"
                    class="rounded-md border border-gray-300 px-3 py-2 text-left text-sm text-gray-700 hover:border-indigo-500 focus:ring-2 focus:ring-indigo-500 focus:outline-hidden dark:border-gray-600 dark:text-gray-300"
                    @click="form.intent = 'artist'"
                >
                    I make music
                </button>
            </div>
        </fieldset>

        <p v-else class="mb-6 text-sm text-gray-600 dark:text-gray-400">
            <button
                type="button"
                class="underline hover:text-gray-900 focus:ring-2 focus:ring-indigo-500 focus:outline-hidden dark:hover:text-gray-100"
                @click="form.intent = otherPath"
            >
                {{ form.intent === 'artist' ? 'Just here to listen instead?' : 'Do you make music? Join as an artist instead.' }}
            </button>
        </p>

        <InputError class="mb-4" :message="form.errors.intent" />

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="name" value="Name" />

                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="mt-4">
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Password" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel for="password_confirmation" value="Confirm Password" />

                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <!-- Location is what makes the map local; say what it is for
                 before asking, and be explicit about what is never asked. -->
            <fieldset class="mt-6 border-t border-gray-200 pt-4 dark:border-gray-700">
                <legend class="sr-only">Where you are</legend>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Your town centres the map on your area and sets the distances you
                    see. Riff never asks for a street address, and never shows your
                    live location.
                </p>

                <div class="mt-4">
                    <InputLabel for="city" value="City" />

                    <TextInput
                        id="city"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="form.city"
                        required
                        autocomplete="address-level2"
                    />

                    <InputError class="mt-2" :message="form.errors.city" />
                </div>

                <div class="mt-4">
                    <InputLabel for="state" value="State/Province" />

                    <TextInput
                        id="state"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="form.state"
                        required
                        autocomplete="address-level1"
                    />

                    <InputError class="mt-2" :message="form.errors.state" />
                </div>

                <div class="mt-4">
                    <InputLabel for="country" value="Country" />

                    <TextInput
                        id="country"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="form.country"
                        required
                        autocomplete="country-name"
                    />

                    <InputError class="mt-2" :message="form.errors.country" />
                </div>
            </fieldset>

            <p v-if="form.intent === 'artist'" class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                Next: your artist profile. Your own account location stays private —
                the artist profile has its own public area, which you choose.
            </p>

            <div class="mt-6 flex items-center justify-end">
                <Link
                    :href="route('login')"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:outline-hidden dark:text-gray-400 dark:hover:text-gray-100"
                >
                    Already registered?
                </Link>

                <PrimaryButton class="ms-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    {{ form.intent === 'artist' ? 'Create account & continue' : 'Register' }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
