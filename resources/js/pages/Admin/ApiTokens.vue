<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { del, get, post } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';
import type { NextAction } from '@/types/shared';

/**
 * API tokens for service accounts only (integrations: e-commerce intake, carriers, ERP), and the
 * endpoints those integrations call. Next action: store a just-issued token, else issue one.
 */
const { can } = useShared();
/** Endpoints meant for other systems, with how they authenticate (Chapter 11 §11.8). */
const INTEGRATIONS = [
    { method: 'POST', path: '/api/v1/intake/sales-orders', what: 'Create a sales order from a shop or marketplace', auth: 'Bearer token of a service account with “sales order intake”' },
    { method: 'POST', path: '/api/v1/intake/carrier-events', what: 'Report a shipment status from a carrier', auth: 'Bearer token of a service account allowed to receive carrier webhooks' },
    { method: 'POST', path: '/api/v1/integrations/carriers/{carrier}/events', what: 'Carrier webhook (shipment events)', auth: 'X-Webhook-Signature (HMAC of the body with the carrier webhook secret in Configuration)' },
    { method: 'GET', path: '/api/v1/meta/schemas', what: 'Every payload schema, for building the integration', auth: 'Bearer token' },
    { method: 'GET', path: '/api/v1/meta/brand', what: 'Public brand profile (name, colours, logos)', auth: 'None' },
];
const nextActions = computed<NextAction[]>(() => {
    if (issued.value) {
        return [{ label: 'I have stored the token', icon: 'check', onClick: () => (issued.value = null), hint: 'Copy it into the other system now — it is never shown again.' }];
    }

    return can('identity.api_token.issue')
        ? [{ label: 'Issue a token', icon: 'key', onClick: () => document.getElementById('tok-name')?.focus(), hint: 'Tokens belong to a service account and act with its roles and warehouses.' }]
        : [];
});
const tokens = ref<Record<string, any>[]>([]);
const draft = reactive<{ user_account_id: string | null; name: string; expires_in_days: number }>({ user_account_id: null, name: '', expires_in_days: 90 });
const issued = ref<string | null>(null);
const error = ref<unknown>(null);

async function load() {
    tokens.value = (await get('/api/v1/admin/api-tokens')).data;
}

async function issue() {
    error.value = null;
    try {
        issued.value = (await post('/api/v1/admin/api-tokens', draft)).data.token;
        draft.name = '';
        await load();
    } catch (e) {
        error.value = e;
    }
}

async function revoke(id: string) {
    error.value = null;
    try {
        await del(`/api/v1/admin/api-tokens/${id}`);
        notify('Token revoked.');
        await load();
    } catch (e) {
        error.value = e;
    }
}

onMounted(() => load().catch((e) => (error.value = e)));
</script>

<template>
    <PageHeader title="API tokens" subtitle="Bearer tokens for service accounts; each acts with its account's roles and scopes" :next="nextActions" />
    <ErrorSummary :error="error" />
    <div v-if="issued" class="mb-4 rounded-brand border border-warning/40 bg-warning/10 p-4 text-sm" role="status">
        <p class="font-semibold">New token — copy it now, it is not shown again:</p>
        <p class="mt-1 break-all font-mono">{{ issued }}</p>
        <UiButton class="mt-2" @click="issued = null">I have stored it</UiButton>
    </div>
    <section class="card mb-6">
        <ul class="divide-y divide-line">
            <li v-for="t in tokens" :key="t.id" class="flex flex-wrap items-center gap-3 py-3 text-sm">
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ t.name }} · {{ t.username }}</p>
                    <p class="text-ink-muted">Created {{ formatDateTime(t.created_at) }} · expires {{ formatDateTime(t.expires_at) || 'never' }} · last used {{ formatDateTime(t.last_used_at) || 'never' }}</p>
                </div>
                <UiButton v-if="can('identity.api_token.revoke')" variant="danger" @click="revoke(t.id)">Revoke</UiButton>
            </li>
        </ul>
        <p v-if="tokens.length === 0" class="text-sm text-ink-muted">No tokens.</p>
    </section>
    <form v-if="can('identity.api_token.issue')" class="card grid gap-3 md:grid-cols-[1fr_1fr_8rem_auto] md:items-end" @submit.prevent="issue">
        <div>
            <label for="tok-account" class="label">Service account</label>
            <ReferencePicker id="tok-account" v-model="draft.user_account_id" resource="accounts" :filters="{ account_type: 'service' }" />
        </div>
        <div>
            <label for="tok-name" class="label">Token name</label>
            <input id="tok-name" v-model="draft.name" class="input" required>
        </div>
        <div>
            <label for="tok-days" class="label">Days valid</label>
            <input id="tok-days" v-model.number="draft.expires_in_days" type="number" min="1" class="input">
        </div>
        <UiButton type="submit" variant="primary" :disabled="!draft.user_account_id || !draft.name">Issue token</UiButton>
    </form>

    <section class="card mt-6" aria-labelledby="integrations-title">
        <h2 id="integrations-title" class="font-semibold">Endpoints for integrations</h2>
        <p class="mb-3 text-sm text-ink-muted">What other systems call with a token from this page. Every call is checked against the service account's permissions and warehouses.</p>
        <ul class="divide-y divide-line text-sm">
            <li v-for="e in INTEGRATIONS" :key="e.path" class="grid gap-1 py-2 md:grid-cols-[minmax(0,22rem)_1fr]">
                <code class="break-all font-mono text-xs"><span class="font-semibold">{{ e.method }}</span> {{ e.path }}</code>
                <span>{{ e.what }} <span class="block text-xs text-ink-muted">{{ e.auth }}</span></span>
            </li>
        </ul>
    </section>
</template>
