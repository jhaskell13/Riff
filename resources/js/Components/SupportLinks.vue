<script setup>
// The public "Support this artist" block. Every link leaves Riff: these
// point at where the artist already gets paid, and Riff handles no money
// and takes no cut (ROADMAP principle 4).
//
// The click beacon is fire-and-forget. Navigation is never held up for it
// and a failure is swallowed on purpose -- a blocked beacon must not cost
// the artist a sale, and the counter is only there to answer "do support
// links get clicked at all".
const props = defineProps({
    links: { type: Array, default: () => [] },
});

// Global axios (resources/js/bootstrap.js), not fetch: it sends the
// XSRF-TOKEN cookie as a header for us, and there is no csrf-token meta tag
// in the layout to read. The links open in a new tab, so this page is never
// unloaded mid-request and no keepalive is needed.
const countClick = (link) => {
    if (!link.clickUrl) {
        return;
    }

    window.axios?.post(link.clickUrl).catch(() => {
        // Swallowed: counting a click must never interrupt one.
    });
};
</script>

<template>
    <div v-if="props.links.length" class="column gap-2">
        <a
            v-for="link in props.links"
            :key="link.id"
            :href="link.url"
            target="_blank"
            rel="noopener noreferrer nofollow"
            class="support-link"
            @click="countClick(link)"
        >
            <q-icon :name="link.icon" size="20px" color="teal-3" />
            <span class="support-link__label text-body2 text-grey-2">{{ link.displayLabel }}</span>
            <!-- The host is shown so nobody clicks blind: the label is the
                 artist's own words, the host is checkable fact. -->
            <span class="support-link__host text-caption text-grey-6">{{ link.host }}</span>
            <q-icon name="open_in_new" size="14px" color="grey-6" />
        </a>
    </div>
</template>

<style scoped>
.support-link {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 12px;
    border-radius: 8px;
    background: #262626;
    text-decoration: none;
}

.support-link:hover {
    background: #2e2e2e;
}

.support-link__label {
    font-weight: 500;
}

.support-link__host {
    margin-left: auto;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 45%;
}
</style>
