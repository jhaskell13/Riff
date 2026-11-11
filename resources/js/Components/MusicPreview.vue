<script setup>
import { computed, ref } from 'vue';

// Click-to-play. The provider's iframe is only inserted after the listener
// asks for it, so simply opening a profile or a map card does not hand a
// third party a page view, and a list of cards costs nothing to render.
const props = defineProps({
    embed: { type: Object, default: null },
    artistName: { type: String, default: '' },
    dense: { type: Boolean, default: false },
});

const playing = ref(false);

// Players have fixed natural heights; too short and the provider's own
// chrome scrolls, too tall and the card wastes space.
const HEIGHTS = {
    spotify: 152,
    soundcloud: 166,
    apple_music: 175,
};

const isVideo = computed(() => props.embed?.provider === 'youtube');
const frameHeight = computed(() => HEIGHTS[props.embed?.provider] ?? 180);
const playable = computed(() => Boolean(props.embed?.embed_url));
</script>

<template>
    <div v-if="embed">
        <!-- Playable: placeholder until the listener presses play -->
        <template v-if="playable">
            <div
                v-if="!playing"
                class="preview-placeholder"
                :class="{ 'preview-placeholder--dense': dense }"
                role="button"
                tabindex="0"
                :aria-label="`Play music by ${artistName} on ${embed.label}`"
                @click="playing = true"
                @keydown.enter.prevent="playing = true"
                @keydown.space.prevent="playing = true"
            >
                <q-icon name="play_circle" size="34px" color="primary" />
                <span class="text-grey-4 text-caption">Play on {{ embed.label }}</span>
            </div>

            <div v-else :class="isVideo ? 'preview-video' : ''">
                <iframe
                    :src="embed.embed_url"
                    :title="`Music by ${artistName} on ${embed.label}`"
                    :height="isVideo ? undefined : frameHeight"
                    width="100%"
                    frameborder="0"
                    loading="lazy"
                    referrerpolicy="strict-origin-when-cross-origin"
                    allow="autoplay; encrypted-media; clipboard-write; picture-in-picture; fullscreen"
                    allowfullscreen
                ></iframe>
            </div>
        </template>

        <!-- Link-only providers (Bandcamp has no public embed URL) -->
        <a
            v-else
            :href="embed.url"
            target="_blank"
            rel="noopener noreferrer"
            class="preview-placeholder preview-placeholder--link"
            :class="{ 'preview-placeholder--dense': dense }"
        >
            <q-icon name="open_in_new" size="24px" color="primary" />
            <span class="text-grey-4 text-caption">Listen on {{ embed.label }}</span>
        </a>
    </div>
</template>

<style scoped>
.preview-placeholder {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 18px 16px;
    border: 1px dashed #4b4b4b;
    border-radius: 10px;
    background: #171717;
    cursor: pointer;
    text-decoration: none;
    transition: border-color 120ms ease, background 120ms ease;
}

.preview-placeholder:hover,
.preview-placeholder:focus-visible {
    border-color: var(--q-primary, #667eea);
    background: #1c1c1c;
}

.preview-placeholder--dense {
    padding: 10px 12px;
}

.preview-video {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
}

.preview-video iframe {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border-radius: 8px;
}

iframe {
    border-radius: 8px;
    display: block;
}

@media (prefers-reduced-motion: reduce) {
    .preview-placeholder {
        transition: none;
    }
}
</style>
