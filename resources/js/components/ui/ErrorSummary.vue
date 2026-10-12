<script setup lang="ts">
import { computed } from 'vue';
import { ApiError } from '@/lib/api';

/** Error summary (Chapter 12 §12.3): message, field errors with allowed values, hint, reference. */
const props = defineProps<{ error: unknown }>();

const e = computed(() => (props.error instanceof ApiError ? props.error : null));
const message = computed(() => (props.error instanceof Error ? props.error.message : props.error ? String(props.error) : ''));
const details = computed(() => {
    const d = e.value?.details ?? {};
    const nested = Array.isArray(d.errors) ? (d.errors as Array<Record<string, unknown>>) : [];

    return nested.map((x) => [x.section ?? x.field ?? x.property, x.message].filter(Boolean).join(': '));
});
</script>

<template>
    <div v-if="error" class="mb-4 rounded-brand border border-danger/40 bg-danger/5 p-4 text-sm" role="alert" aria-live="assertive">
        <p class="font-semibold text-danger">{{ message }}</p>
        <ul v-if="e && e.errors.length" class="mt-2 list-disc space-y-1 pl-5 text-ink">
            <li v-for="(f, i) in e.errors" :key="i">
                <span class="font-medium">{{ f.property }}</span>: {{ f.message }}
                <span v-if="Array.isArray(f.allowed_values)" class="text-ink-muted"> (allowed: {{ (f.allowed_values as string[]).join(', ') }})</span>
            </li>
        </ul>
        <ul v-if="details.length" class="mt-2 list-disc space-y-1 pl-5 text-ink">
            <li v-for="(d, i) in details" :key="i">{{ d }}</li>
        </ul>
        <p v-if="e?.hint" class="mt-2 text-ink-muted">{{ e.hint }}</p>
        <p v-if="e?.correlationId" class="mt-2 text-xs text-ink-muted">Reference: {{ e.correlationId }}</p>
    </div>
</template>
