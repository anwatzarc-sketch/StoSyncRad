<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, put } from '@/lib/api';
import { enumLabel, loadEnums } from '@/lib/meta';
import { notify } from '@/lib/toast';

/** Document numbering patterns per document type: tokens {PREFIX} {WH} {BR} {YYYY} {YY} {MM} {SEQ:n}. */
const rows = ref<Record<string, any>[]>([]);
const error = ref<unknown>(null);

function preview(r: Record<string, any>): string {
    const now = new Date();
    const seq = String(r.next_number ?? 1).padStart(Number(r.sequence_padding ?? 6), '0');

    return String(r.pattern ?? '').replace('{PREFIX}', r.prefix ?? '').replace('{WH}', 'WH1').replace('{BR}', 'HQ').replace('{YYYY}', String(now.getFullYear()))
        .replace('{YY}', String(now.getFullYear()).slice(-2)).replace('{MM}', String(now.getMonth() + 1).padStart(2, '0'))
        .replace(/\{SEQ(?::(\d+))?\}/, (_, n) => (n ? String(r.next_number ?? 1).padStart(Number(n), '0') : seq));
}

async function save(r: Record<string, any>) {
    error.value = null;
    try {
        rows.value = (await put(`/api/v1/admin/numbering/${r.id}`, { prefix: r.prefix, pattern: r.pattern, sequence_padding: Number(r.sequence_padding) })).data;
        notify('Numbering saved.');
    } catch (e) {
        error.value = e;
    }
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    try {
        rows.value = (await get('/api/v1/admin/numbering')).data;
    } catch (e) {
        error.value = e;
    }
});
</script>

<template>
    <PageHeader title="Document numbering" />
    <ErrorSummary :error="error" />
    <ul class="space-y-3">
        <li v-for="r in rows" :key="r.id" class="card">
            <form class="grid gap-3 md:grid-cols-[14rem_8rem_1fr_6rem_auto] md:items-end" @submit.prevent="save(r)">
                <div>
                    <p class="font-semibold">{{ enumLabel('document_type', r.document_type) }}</p>
                    <p class="text-xs text-ink-muted">Next: <span class="font-mono">{{ preview(r) }}</span></p>
                </div>
                <div>
                    <label :for="`prefix-${r.id}`" class="label">Prefix</label>
                    <input :id="`prefix-${r.id}`" v-model="r.prefix" class="input font-mono uppercase" maxlength="12">
                </div>
                <div>
                    <label :for="`pattern-${r.id}`" class="label">Pattern</label>
                    <input :id="`pattern-${r.id}`" v-model="r.pattern" class="input font-mono">
                </div>
                <div>
                    <label :for="`pad-${r.id}`" class="label">Digits</label>
                    <input :id="`pad-${r.id}`" v-model="r.sequence_padding" type="number" min="1" max="12" class="input">
                </div>
                <UiButton type="submit" variant="primary">Save</UiButton>
            </form>
        </li>
    </ul>
</template>
