<script setup>
/**
 * The first thing a visitor sees over the map: what Riff is, and the two
 * reasons anyone is here -- to find music made nearby, or to be found
 * making it. Each path ends at the registration it needs.
 *
 * It is deliberately dismissible and not a gate. The map behind it works
 * without an account, and someone who would rather just look around should
 * be able to; the page reopens this from the toolbar's help button.
 */
defineProps({
    modelValue: { type: Boolean, default: false },
    // Signed-in viewers see the same explanation with their own next
    // steps: they have no registration left to do.
    signedIn: { type: Boolean, default: false },
    reduceMotion: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <q-dialog
        :model-value="modelValue"
        :transition-duration="reduceMotion ? 0 : 200"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <q-card
            dark
            role="dialog"
            aria-labelledby="riff-welcome-title"
            class="riff-welcome bg-riff-surface"
        >
            <q-card-section class="flex items-start gap-2">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1 text-riff-primary">
                        <q-icon name="music_note" size="22px" />
                        <span class="text-lg font-bold">Riff</span>
                    </div>
                    <h2 id="riff-welcome-title" class="m-0 mt-2 text-xl leading-tight font-bold text-riff-text">
                        Find music made near you
                    </h2>
                    <p class="m-0 mt-2 text-sm leading-relaxed text-riff-muted">
                        Every pin is an independent artist, in the area they chose to
                        show. Open one to hear a track, follow who you like, and come
                        back for their shows and releases.
                    </p>
                </div>
                <q-btn
                    v-close-popup
                    flat dense round
                    icon="close"
                    color="grey-5"
                    aria-label="Close"
                />
            </q-card-section>

            <q-card-section class="pt-0">
                <div class="riff-welcome__paths">
                    <!-- Listener -->
                    <div class="riff-welcome__path">
                        <q-icon name="headphones" size="24px" class="text-riff-primary" />
                        <h3 class="m-0 mt-2 text-sm font-semibold text-riff-text">I'm here for the music</h3>
                        <p class="m-0 mt-1 flex-1 text-xs leading-relaxed text-riff-muted">
                            Browse the map now — no account needed. Join to follow artists
                            and keep their updates in one place.
                        </p>
                        <q-btn
                            v-close-popup
                            unelevated rounded no-caps
                            color="primary"
                            padding="xs md"
                            class="mt-3 w-full"
                            label="Explore the map"
                        />
                        <q-btn
                            v-if="!signedIn"
                            flat dense no-caps
                            color="grey-5"
                            padding="xs sm"
                            class="mt-1 w-full"
                            label="Join as a listener"
                            :href="route('register', { intent: 'fan' })"
                            type="a"
                        />
                    </div>

                    <!-- Artist -->
                    <div class="riff-welcome__path">
                        <q-icon name="mic" size="24px" class="text-riff-primary" />
                        <h3 class="m-0 mt-2 text-sm font-semibold text-riff-text">I make music</h3>
                        <p class="m-0 mt-1 flex-1 text-xs leading-relaxed text-riff-muted">
                            Put your band, solo act, or production work on the map so
                            people nearby can find and hear you.
                        </p>
                        <q-btn
                            unelevated rounded no-caps
                            color="primary"
                            padding="xs md"
                            class="mt-3 w-full"
                            :label="signedIn ? 'Create an artist profile' : 'Get on the map'"
                            :href="signedIn ? route('artists.create') : route('register', { intent: 'artist' })"
                            type="a"
                        />
                    </div>
                </div>

                <p class="m-0 mt-4 flex items-start gap-1 text-xs leading-relaxed text-riff-muted">
                    <q-icon name="shield" size="14px" class="mt-0.5 shrink-0" />
                    <span>
                        Artists pick the town they appear in. Riff never shows anyone's
                        street address or live location.
                    </span>
                </p>

                <p v-if="!signedIn" class="m-0 mt-3 text-center text-xs text-riff-muted">
                    Already have an account?
                    <a :href="route('login')" class="text-riff-primary hover:underline">Sign in</a>
                </p>
            </q-card-section>
        </q-card>
    </q-dialog>
</template>

<style scoped>
.riff-welcome {
    width: 100%;
    max-width: 520px;
}

.riff-welcome__paths {
    display: grid;
    gap: 12px;
    grid-template-columns: 1fr;
}

.riff-welcome__path {
    display: flex;
    flex-direction: column;
    border: 1px solid var(--color-riff-line);
    border-radius: 12px;
    padding: 14px;
    background: var(--color-riff-surface-2);
}

@media (min-width: 600px) {
    .riff-welcome__paths {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
