<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ModalDialog from './ui/ModalDialog.vue';
import UiButton from './ui/UiButton.vue';
import ErrorSummary from './ui/ErrorSummary.vue';
import SchemaForm from './schema/SchemaForm.vue';
import { ApiError, api } from '@/lib/api';
import { attemptKey, completeAttempt } from '@/lib/idempotency';
import { whilePosting } from '@/lib/pwa';
import type { ResourceAction } from '@/types/shared';

/**
 * Runs one document action (Chapter 12 §12.7): explains the consequence, collects the action's
 * payload from its schema, sends a stable Idempotency-Key for postings, shows "Posting…" until the
 * server confirms, and keeps the input on conflicts so nothing typed is lost.
 */
const props = defineProps<{ action: ResourceAction | null; record: Record<string, any> }>();
const emit = defineEmits<{ close: []; done: [result: unknown] }>();

const form = ref<Record<string, unknown>>({});
const busy = ref(false);
const error = ref<unknown>(null);

const DANGER = /(cancel|reject|archive|blacklist|close|lock|deactivate|suspend|revoke|scrap|delete)/;
const danger = computed(() => !!props.action && DANGER.test(props.action.name));
const uri = computed(() => props.action?.uri.replace(/\{[a-zA-Z]+\}/, props.record.id) ?? '');
const scope = computed(() => `${props.action?.method}:${uri.value}`);
const fieldErrors = computed(() => (error.value instanceof ApiError ? error.value.fieldErrors() : {}));

watch(() => props.action, (a) => {
    error.value = null;
    form.value = a?.schema === 'decision' || a?.schema === 'DecisionSchema' || a?.schema?.toLowerCase().includes('decision') ? { version: props.record.version } : {};
});

async function run() {
    if (!props.action) {
        return;
    }
    busy.value = true;
    error.value = null;
    try {
        const body = props.action.no_body ? undefined : Object.fromEntries(Object.entries(form.value).filter(([, v]) => v !== undefined && v !== ''));
        const result = await whilePosting(() => api(uri.value, {
            method: props.action!.method,
            body,
            idempotencyKey: props.action!.idempotent ? attemptKey(scope.value) : undefined,
        }));
        completeAttempt(scope.value);
        emit('done', result);
    } catch (e) {
        error.value = e;
        if (e instanceof ApiError && e.status === 409) {
            completeAttempt(scope.value);
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ModalDialog :open="action !== null" :title="action?.label ?? ''" wide @close="busy || emit('close')">
        <p class="mb-4 text-sm text-ink-muted">
            <template v-if="action?.idempotent">This posts to the stock ledger. It cannot be edited afterwards — only reversed by an authorised person.</template>
            <template v-else-if="danger">This cannot be undone from here. Give a reason where asked.</template>
            <template v-else>Confirm to continue. The server re-checks your permission, warehouse scope and the document's state.</template>
        </p>
        <ErrorSummary :error="error" />
        <SchemaForm
            v-if="action?.schema && !action.no_body"
            :key="action.uri"
            v-model="form"
            :schema-name="action.schema"
            operation="create"
            :errors="fieldErrors"
            :exclude="['version']"
        />
        <template #footer>
            <UiButton :disabled="busy" @click="emit('close')">Back</UiButton>
            <UiButton :variant="danger ? 'danger' : 'primary'" :loading="busy" @click="run">
                {{ busy ? (action?.idempotent ? 'Posting…' : 'Working…') : action?.label }}
            </UiButton>
        </template>
    </ModalDialog>
</template>
