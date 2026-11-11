<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

/**
 * The report queue. The actions offered per report come from the server
 * (ModerationAction::optionsFor), so this page cannot offer a decision the
 * request would refuse.
 */
defineProps({
    reports: { type: Object, required: true },
    status: { type: String, default: 'open' },
    tabs: { type: Array, default: () => [] },
    openCount: { type: Number, default: 0 },
});

// Per-report note: the hand-written part of the audit trail.
const notes = ref({});
const pendingId = ref(null);

const resolve = (report, action) => {
    if (pendingId.value) {
        return;
    }

    pendingId.value = report.id;

    router.post(
        route('moderation.resolve', report.id),
        { action, note: notes.value[report.id] ?? '' },
        {
            preserveScroll: true,
            onFinish: () => {
                pendingId.value = null;
                delete notes.value[report.id];
            },
        },
    );
};

const when = (iso) => (iso ? new Date(iso).toLocaleString() : null);
</script>

<template>
    <Head title="Moderation" />

    <AuthenticatedLayout>
        <q-page class="bg-riff-bg">
            <div class="mx-auto max-w-3xl px-4 py-8">
                <header class="mb-6">
                    <h1 class="m-0 text-2xl font-bold text-riff-text">Reports</h1>
                    <p class="m-0 mt-1 text-sm text-riff-muted">
                        {{ openCount }} open {{ openCount === 1 ? 'report' : 'reports' }}.
                        Resolving one closes every other open report about the same
                        artist or post.
                    </p>
                </header>

                <nav class="mb-6 flex gap-2" aria-label="Report status">
                    <Link
                        v-for="tab in tabs"
                        :key="tab.value"
                        :href="route('moderation.index', { status: tab.value })"
                        class="rounded-full border px-4 py-1 text-sm transition-colors"
                        :class="tab.value === status
                            ? 'border-riff-primary bg-riff-primary text-white'
                            : 'border-riff-line text-riff-muted hover:text-riff-text'"
                        :aria-current="tab.value === status ? 'page' : undefined"
                    >
                        {{ tab.label }}
                    </Link>
                </nav>

                <p v-if="reports.data.length === 0" class="rounded-lg border border-riff-line bg-riff-surface p-8 text-center text-sm text-riff-muted">
                    Nothing here.
                </p>

                <article
                    v-for="report in reports.data"
                    :key="report.id"
                    class="mb-4 rounded-lg border border-riff-line bg-riff-surface p-4"
                >
                    <!-- The complaint -->
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-riff-surface-2 px-2 py-0.5 text-xs font-semibold text-riff-text">
                            {{ report.reasonLabel }}
                        </span>
                        <span class="text-xs text-riff-muted">
                            {{ report.subject.kind }} · reported by {{ report.reporter ?? 'a deleted account' }}
                            · {{ when(report.createdAt) }}
                        </span>
                    </div>

                    <p v-if="report.details" class="m-0 mt-3 text-sm leading-relaxed whitespace-pre-line text-riff-text">
                        “{{ report.details }}”
                    </p>

                    <!-- What was reported -->
                    <div class="mt-3 rounded-md border border-riff-line bg-riff-surface-2 p-3">
                        <div class="flex items-start gap-3">
                            <q-avatar size="40px" :color="report.subject.imageUrl ? '' : 'grey-9'" text-color="grey-5">
                                <img v-if="report.subject.imageUrl" :src="report.subject.imageUrl" :alt="`${report.subject.title} photo`" />
                                <q-icon v-else :name="{ post: 'chat', venue: 'storefront', show: 'event' }[report.subject.kind] ?? 'music_note'" size="20px" />
                            </q-avatar>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a
                                        v-if="report.subject.url"
                                        :href="report.subject.url"
                                        class="text-sm font-semibold break-words text-riff-primary hover:underline"
                                        target="_blank"
                                        rel="noopener"
                                    >{{ report.subject.title }}</a>
                                    <span v-else class="text-sm font-semibold break-words text-riff-text">
                                        {{ report.subject.title }}
                                    </span>
                                    <span
                                        v-if="report.subject.state"
                                        class="rounded-full bg-amber-900/60 px-2 py-0.5 text-[11px] text-amber-200"
                                    >{{ report.subject.state }}</span>
                                </div>
                                <div v-if="report.subject.detail" class="text-xs break-words text-riff-muted">
                                    {{ report.subject.detail }}
                                </div>
                                <p v-if="report.subject.body" class="m-0 mt-2 text-sm leading-relaxed whitespace-pre-line text-riff-text">
                                    {{ report.subject.body }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- The decision -->
                    <template v-if="report.status === 'open'">
                        <q-input
                            :model-value="notes[report.id] ?? ''"
                            dark dense outlined
                            class="mt-3"
                            bg-color="grey-9"
                            color="primary"
                            label="Note for the record (optional)"
                            label-color="grey-5"
                            maxlength="1000"
                            @update:model-value="(value) => (notes[report.id] = value)"
                        />

                        <div class="mt-3 flex flex-wrap gap-2">
                            <q-btn
                                v-for="option in report.actions"
                                :key="option.value"
                                unelevated rounded no-caps
                                padding="xs md"
                                :color="option.value === 'dismiss' ? 'grey-7' : 'primary'"
                                :label="option.label"
                                :loading="pendingId === report.id"
                                @click="resolve(report, option.value)"
                            />
                        </div>
                    </template>

                    <p v-else class="m-0 mt-3 text-xs text-riff-muted">
                        {{ report.statusLabel }}<template v-if="report.resolutionLabel"> — {{ report.resolutionLabel }}</template>
                        by {{ report.resolvedBy ?? 'a deleted account' }} on {{ when(report.resolvedAt) }}
                        <span v-if="report.note" class="mt-1 block text-riff-text">“{{ report.note }}”</span>
                    </p>
                </article>

                <!-- Pagination -->
                <div v-if="reports.links.prev || reports.links.next" class="flex justify-between py-4">
                    <!-- Neutral labels: the open queue is oldest-first,
                         the settled tabs newest-first. -->
                    <Link v-if="reports.links.prev" :href="reports.links.prev" class="text-sm text-riff-primary" preserve-scroll>
                        &larr; Previous
                    </Link>
                    <span v-else></span>
                    <Link v-if="reports.links.next" :href="reports.links.next" class="text-sm text-riff-primary" preserve-scroll>
                        Next &rarr;
                    </Link>
                </div>
            </div>
        </q-page>
    </AuthenticatedLayout>
</template>
