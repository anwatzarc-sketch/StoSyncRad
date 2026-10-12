<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, post } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useShared } from '@/composables/useShared';

/** System health (Chapter 14 §14.9): preflight, append-only protection, queue, outbox, scheduler. */
const { can } = useShared();
const health = ref<Record<string, any> | null>(null);
const maintenance = ref<Record<string, any> | null>(null);
const reason = ref('');
const error = ref<unknown>(null);

async function load() {
    try {
        health.value = (await get('/api/v1/admin/health')).data;
    } catch (e) {
        error.value = e;
    }
}

async function setMaintenance(enabled: boolean) {
    error.value = null;
    try {
        maintenance.value = (await post('/api/v1/admin/maintenance', { enabled, reason: reason.value || null })).data;
    } catch (e) {
        error.value = e;
    }
}

onMounted(load);
</script>

<template>
    <PageHeader title="System health">
        <template #actions><UiButton icon="refresh" @click="load">Re-check</UiButton></template>
    </PageHeader>
    <ErrorSummary :error="error" />
    <template v-if="health">
        <div class="card mb-4 flex items-center gap-3">
            <StatusBadge :value="health.status" :label="health.status === 'pass' ? 'Healthy' : health.status === 'warn' ? 'Needs attention' : 'Failing'" />
            <span class="text-sm text-ink-muted">Database {{ health.ready.database ? 'reachable' : 'unreachable' }} · cache {{ health.ready.cache ? 'working' : 'failing' }}
                <template v-if="health.reconciliation"> · last reconciliation {{ formatDateTime(health.reconciliation.started_at) }} ({{ health.reconciliation.findings_count }} findings)</template>
            </span>
        </div>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th scope="col">Check</th><th scope="col">Status</th><th scope="col">Detail</th><th scope="col">What to do</th></tr></thead>
                <tbody>
                    <tr v-for="c in health.checks" :key="c.check">
                        <td class="font-mono text-xs">{{ c.check }}</td>
                        <td><StatusBadge :value="c.status" :label="c.status.toUpperCase()" /></td>
                        <td>{{ c.message }}</td>
                        <td class="text-ink-muted">{{ c.remediation }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </template>
    <section v-if="can('platform.platform.manage')" class="card mt-6 space-y-3">
        <h2 class="text-lg font-semibold">Maintenance mode</h2>
        <p class="text-sm text-ink-muted">Everyone except holders of the bypass link sees a maintenance page. Required before reopening Platform Setup.</p>
        <input v-model="reason" class="input" placeholder="Reason (audited)" aria-label="Reason">
        <div class="flex gap-2">
            <UiButton variant="danger" @click="setMaintenance(true)">Enter maintenance</UiButton>
            <UiButton @click="setMaintenance(false)">Leave maintenance</UiButton>
        </div>
        <p v-if="maintenance?.bypass_url" class="break-all rounded-brand bg-warning/10 p-3 font-mono text-sm">Bypass link (keep private): {{ maintenance.bypass_url }}</p>
    </section>
</template>
