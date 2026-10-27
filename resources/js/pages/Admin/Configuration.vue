<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { nextSetupStep } from '@/lib/adminFlow';
import { useShared } from '@/composables/useShared';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import ConfigValueInput from '@/components/ConfigValueInput.vue';
import { get, post, put } from '@/lib/api';
import { humanize, loadEnums } from '@/lib/meta';
import { notify } from '@/lib/toast';
import type { ConfigKey, NextAction } from '@/types/shared';

/**
 * Configuration registry (Chapter 03 §3.2): every declared key with its effective value and where it
 * comes from (default, global or a scope override). Each group has its own manage permission. Keys
 * with their own rules are saved through their dedicated endpoint (DEDICATED).
 */
type Row = ConfigKey & { effective: unknown; source: string; source_scope_id: string | null; can_manage: boolean };

const { nav } = useShared();
/** Next action: the next going-live step (lib/adminFlow). */
const nextActions = computed<NextAction[]>(() => [nextSetupStep('/admin/config', nav.value)]
    .filter((a): a is NextAction => !!a));
const rows = ref<Row[]>([]);
const groups = ref<string[]>([]);
const group = ref('');
const q = ref('');
const editing = reactive<Record<string, { value: unknown; scope: string; scopeId: string | null; reason: string; busy: boolean }>>({});
const error = ref<unknown>(null);

const SCOPE_RESOURCES: Record<string, string> = { warehouse: 'warehouses', branch: 'branches', category: 'item-categories', item: 'items' };
/** Keys changed through their own endpoint (its own checks and step-up); the generic one refuses them. */
const DEDICATED: Record<string, (value: unknown, reason: string | null) => Promise<unknown>> = {
    'identity.accounts_per_person': (value, reason) => put('/api/v1/admin/accounts-per-person-policy', { policy: value, reason }),
};
const visible = computed(() => rows.value.filter((r) => (!group.value || r.group === group.value)
    && (!q.value || `${r.key} ${r.label} ${r.help}`.toLowerCase().includes(q.value.toLowerCase()))));

async function load() {
    try {
        const r = await get('/api/v1/admin/config');
        rows.value = r.data;
        groups.value = r.meta.groups;
    } catch (e) {
        error.value = e;
    }
}

function edit(row: Row) {
    editing[row.key] = { value: row.sensitive ? undefined : structuredClone(row.effective), scope: 'global', scopeId: null, reason: '', busy: false };
}

async function save(row: Row) {
    const e = editing[row.key];
    e.busy = true;
    error.value = null;
    try {
        const dedicated = DEDICATED[row.key];
        await (dedicated ? dedicated(e.value, e.reason || null)
            : put(`/api/v1/admin/config/${row.key}`, { value: e.value, scope_type: e.scope, scope_id: e.scope === 'global' ? null : e.scopeId, reason: e.reason || null }));
        notify(`${row.label} saved.${row.restart_required ? ' Takes effect after a restart.' : ''}`);
        delete editing[row.key];
        await load();
    } catch (err) {
        error.value = err;
        e.busy = false;
    }
}

function show(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    if (typeof value === 'boolean') {
        return value ? 'On' : 'Off';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    await load();
});

/** Test e-mail delivery with the saved mail settings (shown with the messaging group). */
const canTestMail = computed(() => rows.value.some((r) => r.key.startsWith('messaging.mail.') && r.can_manage));
const testTo = ref('');
const testBusy = ref(false);
async function testMail() {
    testBusy.value = true;
    error.value = null;
    try {
        const { data } = await post('/api/v1/admin/mail/test', { to: testTo.value });
        notify(data.status === 'written_to_log' ? 'Mail delivery is set to "log": the test was written to the log file, not sent.' : `Test e-mail sent to ${testTo.value}.`,
            data.status === 'written_to_log' ? 'info' : 'success');
    } catch (e) {
        error.value = e;
    } finally {
        testBusy.value = false;
    }
}
</script>

<template>
    <PageHeader title="Configuration" :next="nextActions" subtitle="Every behaviour of the platform, with defaults and scoped overrides" />
    <ErrorSummary :error="error" />
    <div class="card mb-4 grid gap-3 md:grid-cols-[16rem_1fr]">
        <select v-model="group" class="input" aria-label="Group">
            <option value="">All groups</option>
            <option v-for="g in groups" :key="g" :value="g">{{ humanize(g) }}</option>
        </select>
        <input v-model="q" type="search" class="input" placeholder="Search settings…" aria-label="Search settings">
    </div>
    <form v-if="canTestMail && (group === 'messaging' || q.includes('mail'))" class="card mb-4 flex flex-wrap items-end gap-3" @submit.prevent="testMail">
        <div class="min-w-64 flex-1">
            <label for="mail-test-to" class="label">Test e-mail delivery</label>
            <input id="mail-test-to" v-model="testTo" type="email" class="input" placeholder="you@example.com" required>
            <p class="help">Save the mail settings below first (Mail delivery = smtp, server, port, security, username, password), then send a test.</p>
        </div>
        <UiButton type="submit" variant="primary" icon="bell" :loading="testBusy">Send test e-mail</UiButton>
    </form>
    <ul class="space-y-3">
        <li v-for="row in visible" :key="row.key" class="card">
            <div class="flex flex-wrap items-start gap-3">
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ row.label }}</p>
                    <p class="text-sm text-ink-muted">{{ row.help }}</p>
                    <p class="mt-1 font-mono text-xs text-ink-muted">{{ row.key }}</p>
                </div>
                <div class="text-right text-sm">
                    <p class="font-medium">{{ row.sensitive ? (row.effective ? '••••••' : 'Not set') : show(row.effective) }}</p>
                    <p class="text-xs text-ink-muted">{{ row.source === 'default' ? 'Default' : `Set at ${row.source}` }}</p>
                </div>
                <UiButton v-if="row.can_manage && !editing[row.key]" icon="edit" @click="edit(row)">Change</UiButton>
            </div>
            <form v-if="editing[row.key]" class="mt-4 grid gap-3 border-t border-line pt-4 md:grid-cols-2" @submit.prevent="save(row)">
                <div class="md:col-span-2">
                    <label :for="`cfg-${row.key}`" class="label">New value</label>
                    <ConfigValueInput :id="`cfg-${row.key}`" v-model="editing[row.key].value" :definition="row" />
                </div>
                <div v-if="row.scopes.length > 1">
                    <label :for="`scope-${row.key}`" class="label">Applies to</label>
                    <select :id="`scope-${row.key}`" v-model="editing[row.key].scope" class="input">
                        <option v-for="s in row.scopes" :key="s" :value="s">{{ s === 'global' ? 'Whole company' : `One ${s}` }}</option>
                    </select>
                </div>
                <div v-if="editing[row.key].scope !== 'global'">
                    <label :for="`scope-id-${row.key}`" class="label">{{ humanize(editing[row.key].scope) }}</label>
                    <ReferencePicker :id="`scope-id-${row.key}`" v-model="editing[row.key].scopeId" :resource="SCOPE_RESOURCES[editing[row.key].scope]" />
                </div>
                <div class="md:col-span-2">
                    <label :for="`reason-${row.key}`" class="label">Reason (kept in the audit log)</label>
                    <input :id="`reason-${row.key}`" v-model="editing[row.key].reason" class="input">
                </div>
                <div class="flex gap-2 md:col-span-2">
                    <UiButton @click="delete editing[row.key]">Cancel</UiButton>
                    <UiButton type="submit" variant="primary" :loading="editing[row.key].busy">Save</UiButton>
                </div>
            </form>
        </li>
    </ul>
</template>
