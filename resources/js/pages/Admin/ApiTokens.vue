<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { del, get, post } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';

/** API tokens for service accounts only (integrations: e-commerce intake, carriers, ERP). */
const { can } = useShared();
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
    <PageHeader title="API tokens" subtitle="Bearer tokens for service accounts; each acts with its account's roles and scopes" />
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
</template>
