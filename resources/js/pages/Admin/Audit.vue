<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { buildUrl, download, get } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useShared } from '@/composables/useShared';
import type { NextAction } from '@/types/shared';

/**
 * Append-only audit trail (Chapter 14 §14.5): who did what, to which record, why, with correlation ids;
 * and the access review export (every account, role, permission and scope) for periodic sign-off.
 */
const { can } = useShared();
const nextActions = computed<NextAction[]>(() => [
    ...(can('identity.access_review.export') ? [{ label: 'Download the access review', icon: 'download', onClick: () => void accessReview(),
        hint: 'Who can do what, where — for the periodic access sign-off.' }] : []),
    ...(can('identity.security_event.read') ? [{ label: 'Security events', icon: 'shield', href: '/admin/security-events' }] : []),
]);
const filters = reactive({ event_type: '', subject_type: '', subject_id: '', from: '', to: '' });
const events = ref<Record<string, any>[]>([]);
const cursor = ref<string | null>(null);
const expanded = ref<string | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);

const query = () => Object.fromEntries(Object.entries(filters).filter(([, v]) => v));

async function load(append = false) {
    loading.value = true;
    error.value = null;
    try {
        const r = await get('/api/v1/audit/events', { ...query(), cursor: append ? cursor.value : undefined });
        events.value = append ? [...events.value, ...r.data] : r.data;
        cursor.value = r.meta?.next_cursor ?? null;
    } catch (e) {
        error.value = e;
    } finally {
        loading.value = false;
    }
}

async function exportCsv() {
    try {
        await download(buildUrl('/api/v1/audit/events/export', query()), 'audit.csv');
    } catch (e) {
        error.value = e;
    }
}

async function accessReview() {
    try {
        await download('/api/v1/admin/access-review', 'access-review.csv');
    } catch (e) {
        error.value = e;
    }
}

onMounted(load);
</script>

<template>
    <PageHeader title="Audit log" :next="nextActions">
        <template #actions>
            <UiButton v-if="can('audit.event.export')" icon="download" @click="exportCsv">Export CSV</UiButton>
        </template>
    </PageHeader>
    <form class="card mb-4 grid gap-3 md:grid-cols-5" @submit.prevent="load()">
        <input v-model="filters.event_type" class="input" placeholder="Event (prefix), e.g. inventory." aria-label="Event type">
        <input v-model="filters.subject_type" class="input" placeholder="Record type, e.g. transfers" aria-label="Record type">
        <input v-model="filters.subject_id" class="input" placeholder="Record id" aria-label="Record id">
        <input v-model="filters.from" type="date" class="input" aria-label="From">
        <div class="flex gap-2">
            <input v-model="filters.to" type="date" class="input" aria-label="To">
            <UiButton type="submit" variant="primary">Filter</UiButton>
        </div>
    </form>
    <ErrorSummary :error="error" />
    <div class="card">
        <ul class="divide-y divide-line">
            <li v-for="e in events" :key="e.id" class="py-3 text-sm">
                <button type="button" class="flex w-full flex-wrap items-baseline gap-x-3 text-left" :aria-expanded="expanded === e.id" @click="expanded = expanded === e.id ? null : e.id">
                    <span class="font-mono text-xs text-ink-muted">{{ formatDateTime(e.occurred_at) }}</span>
                    <span class="font-medium">{{ e.event_type }}</span>
                    <span class="text-ink-muted">{{ e.subject_type }} {{ e.subject_id }}</span>
                    <span v-if="e.is_failed_attempt" class="text-danger">failed attempt</span>
                </button>
                <div v-if="expanded === e.id" class="mt-2 grid gap-2 rounded-brand bg-surface-muted p-3 text-xs md:grid-cols-2">
                    <p><strong>Actor:</strong> {{ e.actor_account_id ?? e.actor_type }}</p>
                    <p><strong>Correlation:</strong> {{ e.correlation_id }}</p>
                    <p v-if="e.reason" class="md:col-span-2"><strong>Reason:</strong> {{ e.reason }}</p>
                    <pre v-if="e.before" class="overflow-x-auto whitespace-pre-wrap"><strong>Before</strong> {{ JSON.stringify(e.before, null, 1) }}</pre>
                    <pre v-if="e.after" class="overflow-x-auto whitespace-pre-wrap"><strong>After</strong> {{ JSON.stringify(e.after, null, 1) }}</pre>
                </div>
            </li>
        </ul>
        <p v-if="!loading && events.length === 0" class="py-8 text-center text-sm text-ink-muted">No events match.</p>
        <div v-if="cursor" class="mt-3 text-center"><UiButton :loading="loading" @click="load(true)">Load more</UiButton></div>
    </div>
</template>
