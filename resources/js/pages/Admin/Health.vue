<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, post } from '@/lib/api';
import { enumLabel, loadEnums } from '@/lib/meta';
import { formatDateTime } from '@/lib/format';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';
import type { Column } from '@/components/ui/table';
import type { NextAction } from '@/types/shared';

/**
 * System health (Chapter 14 §14.9): preflight, append-only protection, queue, outbox, scheduler; the
 * system stock locations every warehouse relies on (in transit, adjustments, scrap…); maintenance
 * mode and, from maintenance, reopening Platform Setup (step-up, audited).
 */
const { can, nav } = useShared();
const health = ref<Record<string, any> | null>(null);
const maintenance = ref<Record<string, any> | null>(null);
const reason = ref('');
const error = ref<unknown>(null);
const locations = ref<Record<string, any>[] | null>(null);
const reopenReason = ref('');
const reopening = ref(false);

const locationColumns: Column[] = [
    { key: 'code', label: 'Code' },
    { key: 'location_type', label: 'Type', format: (v) => enumLabel('inventory_location_type', v) },
    { key: 'name', label: 'Name' },
    { key: 'warehouse_id', label: 'Warehouse', format: (v) => (v ? 'One warehouse' : 'Company-wide') },
];
const failing = computed(() => (health.value?.checks ?? []).filter((c: Record<string, any>) => c.status !== 'pass'));
/** Next action: fix the first failing check, retry stuck messages, or review reconciliation differences. */
const nextActions = computed<NextAction[]>(() => {
    if (!health.value) {
        return [];
    }
    const out: NextAction[] = [];
    if (failing.value.length) {
        out.push({ label: 'Re-check after fixing', icon: 'refresh', onClick: () => void load(), hint: `${failing.value[0].check}: ${failing.value[0].remediation ?? failing.value[0].message}` });
    }
    if (nav.value.some((s) => s.items.some((i) => i.href === '/admin/webhooks'))) {
        out.push({ label: 'Webhooks & outbox', icon: 'link', href: '/admin/webhooks' });
    }
    if ((health.value.reconciliation?.findings_count ?? 0) > 0 && can('inventory.reconciliation.read')) {
        out.push({ label: 'Review stock differences', icon: 'alert', href: '/r/reconciliation-findings?filter[status]=open,investigating' });
    }

    return out.length ? out : [{ label: 'Back to operations', icon: 'home', href: '/', hint: 'Everything passes. People can start working.' }];
});

async function load() {
    try {
        health.value = (await get('/api/v1/admin/health')).data;
    } catch (e) {
        error.value = e;
    }
}

async function loadLocations() {
    if (!can('platform.inventory_locations.manage')) {
        return;
    }
    locations.value = (await get('/api/v1/admin/inventory-locations').catch(() => ({ data: [] }))).data;
}

async function setMaintenance(enabled: boolean) {
    error.value = null;
    try {
        maintenance.value = (await post('/api/v1/admin/maintenance', { enabled, reason: reason.value || null })).data;
    } catch (e) {
        error.value = e;
    }
}

async function reopenSetup() {
    if (!window.confirm('Reopen Platform Setup? The setup wizard becomes reachable again until it is locked once more.')) {
        return;
    }
    reopening.value = true;
    error.value = null;
    try {
        await post('/api/v1/admin/setup/reopen', { reason: reopenReason.value || null });
        notify('Platform Setup reopened.');
        window.location.assign('/setup');
    } catch (e) {
        error.value = e;
    } finally {
        reopening.value = false;
    }
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    await Promise.all([load(), loadLocations()]);
});
</script>

<template>
    <PageHeader title="System health" :next="nextActions">
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
    <section v-if="locations" class="card mt-6">
        <h2 class="text-lg font-semibold">System stock locations</h2>
        <p class="mb-3 text-sm text-ink-muted">Where stock is booked when it is not on a shelf: in transit, being adjusted, scrapped or with a customer. Created by the system.</p>
        <DataTable :columns="locationColumns" :rows="locations" empty="No system locations yet — they are created with the first warehouse." caption="System stock locations" />
    </section>
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
    <section v-if="can('platform.setup.reopen')" class="card mt-6 space-y-3 border-danger/30">
        <h2 class="text-lg font-semibold">Reopen Platform Setup</h2>
        <p class="text-sm text-ink-muted">Only for re-running set-up steps (for example after moving servers). Enter maintenance first; you will be asked to confirm your identity.</p>
        <input v-model="reopenReason" class="input" placeholder="Reason (audited)" aria-label="Reason for reopening setup">
        <UiButton variant="danger" :loading="reopening" @click="reopenSetup">Reopen setup</UiButton>
    </section>
</template>
