<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, post } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';

/** Outbound webhooks (Chapter 11 §11.8): HMAC-signed events, deliveries with retries, dead letters. */
const { can } = useShared();
const endpoints = ref<Record<string, any>[]>([]);
const deliveries = ref<Record<string, any>[]>([]);
const dead = ref<Record<string, any>[]>([]);
const draft = reactive({ name: '', url: '', events: 'transfers.*\nreceiving.*' });
const secret = ref<string | null>(null);
const error = ref<unknown>(null);

async function load() {
    endpoints.value = (await get('/api/v1/admin/webhooks')).data;
    deliveries.value = (await get('/api/v1/admin/webhook-deliveries', { status: 'failed' })).data;
    if (can('messaging.outbox.read')) {
        dead.value = (await get('/api/v1/admin/outbox', { status: 'dead' })).data;
    }
}

async function register() {
    error.value = null;
    try {
        const r = await post('/api/v1/admin/webhooks', { name: draft.name, url: draft.url, event_types: draft.events.split('\n').map((s) => s.trim()).filter(Boolean) });
        secret.value = r.data.secret;
        Object.assign(draft, { name: '', url: '' });
        await load();
    } catch (e) {
        error.value = e;
    }
}

async function action(path: string, body?: unknown, message = 'Done.') {
    error.value = null;
    try {
        const r = await post(path, body);
        if (r?.data?.secret) {
            secret.value = r.data.secret;
        }
        notify(message);
        await load();
    } catch (e) {
        error.value = e;
    }
}

onMounted(() => load().catch((e) => (error.value = e)));
</script>

<template>
    <PageHeader title="Webhooks" subtitle="Signed with HMAC-SHA256: header X-Webhook-Signature: t=<unix>,v1=<hex of t.body>" />
    <ErrorSummary :error="error" />
    <div v-if="secret" class="mb-4 rounded-brand border border-warning/40 bg-warning/10 p-4 text-sm" role="status">
        <p class="font-semibold">Signing secret — copy it now, it is not shown again:</p>
        <p class="mt-1 break-all font-mono">{{ secret }}</p>
        <UiButton class="mt-2" @click="secret = null">I have stored it</UiButton>
    </div>

    <section class="card mb-6">
        <h2 class="mb-3 text-lg font-semibold">Endpoints</h2>
        <ul class="divide-y divide-line">
            <li v-for="e in endpoints" :key="e.id" class="flex flex-wrap items-center gap-3 py-3">
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ e.name }}</p>
                    <p class="break-all text-sm text-ink-muted">{{ e.url }}</p>
                    <p class="text-xs text-ink-muted">{{ (e.event_types ?? []).join(', ') }}<template v-if="e.last_success_at"> · last delivered {{ formatDateTime(e.last_success_at) }}</template></p>
                </div>
                <StatusBadge :value="e.is_active ? 'active' : 'blocked'" :label="e.is_active ? 'Active' : 'Paused'" />
                <template v-if="can('messaging.webhook.manage')">
                    <UiButton @click="action(`/api/v1/admin/webhooks/${e.id}/active`, { is_active: !e.is_active })">{{ e.is_active ? 'Pause' : 'Resume' }}</UiButton>
                    <UiButton @click="action(`/api/v1/admin/webhooks/${e.id}/rotate-secret`, undefined, 'Secret rotated.')">Rotate secret</UiButton>
                </template>
            </li>
        </ul>
        <form v-if="can('messaging.webhook.manage')" class="mt-4 grid gap-3 border-t border-line pt-4 md:grid-cols-2" @submit.prevent="register">
            <div>
                <label for="wh-name" class="label">Name</label>
                <input id="wh-name" v-model="draft.name" class="input" required>
            </div>
            <div>
                <label for="wh-url" class="label">HTTPS URL</label>
                <input id="wh-url" v-model="draft.url" type="url" class="input" placeholder="https://" required>
            </div>
            <div class="md:col-span-2">
                <label for="wh-events" class="label">Events (one per line, * allowed)</label>
                <textarea id="wh-events" v-model="draft.events" class="input min-h-20 font-mono text-sm" />
            </div>
            <div><UiButton type="submit" variant="primary">Register endpoint</UiButton></div>
        </form>
    </section>

    <section class="card mb-6">
        <h2 class="mb-3 text-lg font-semibold">Failed deliveries</h2>
        <p v-if="deliveries.length === 0" class="text-sm text-ink-muted">None.</p>
        <ul class="divide-y divide-line">
            <li v-for="d in deliveries" :key="d.id" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                <span class="flex-1">{{ formatDateTime(d.created_at) }} · attempt {{ d.attempts }} · {{ d.last_error ?? `HTTP ${d.last_status_code}` }}</span>
                <UiButton v-if="can('messaging.webhook.manage')" @click="action(`/api/v1/admin/webhook-deliveries/${d.id}/retry`, undefined, 'Retry queued.')">Retry</UiButton>
            </li>
        </ul>
    </section>

    <section v-if="can('messaging.outbox.read')" class="card">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-lg font-semibold">Dead-lettered events</h2>
            <UiButton v-if="dead.length && can('messaging.outbox.retry')" @click="action('/api/v1/admin/outbox/retry', {}, 'Events re-queued.')">Retry all</UiButton>
        </div>
        <p v-if="dead.length === 0" class="text-sm text-ink-muted">None.</p>
        <ul class="divide-y divide-line text-sm">
            <li v-for="o in dead" :key="o.id" class="py-2">{{ o.event_type }} · {{ formatDateTime(o.created_at) }} · {{ o.last_error }}</li>
        </ul>
    </section>
</template>
