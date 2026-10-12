<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { api, download, get, post } from '@/lib/api';
import { notify } from '@/lib/toast';
import type { Column } from '@/components/ui/table';

/**
 * CSV / XLSX imports (Chapter 11 §11.9): upload → validated preview with per-row errors →
 * confirm (processed in the background) → error file. Each import type has its own permission.
 */
interface ImportTypeInfo { type: string; label: string; allowed: boolean; columns?: string[]; required?: string[] }

const types = ref<ImportTypeInfo[]>([]);
const type = ref<string>('');
const file = ref<File | null>(null);
const batch = ref<Record<string, any> | null>(null);
const rows = ref<Record<string, any>[]>([]);
const busy = ref(false);
const error = ref<unknown>(null);
let poll: ReturnType<typeof setInterval> | undefined;

const allowedTypes = computed(() => types.value.filter((t) => t.allowed));
const rowColumns: Column[] = [
    { key: 'row_number', label: 'Row' },
    { key: 'status', label: 'Status' },
    { key: 'errors', label: 'Problems', format: (v) => (Array.isArray(v) ? v.map((e: any) => (typeof e === 'string' ? e : `${e.property ?? e.field}: ${e.message}`)).join('; ') : v ? JSON.stringify(v) : '—') },
    { key: 'data', label: 'Data', format: (v) => (v ? Object.entries(v).slice(0, 4).map(([k, x]) => `${k}=${x}`).join(', ') : '—') },
];

onMounted(async () => {
    try {
        types.value = (await get('/api/v1/imports/types')).data;
        type.value = allowedTypes.value[0]?.type ?? '';
    } catch (e) {
        error.value = e;
    }
});
onBeforeUnmount(() => clearInterval(poll));

async function refresh() {
    if (!batch.value) {
        return;
    }
    const r = await get(`/api/v1/imports/${batch.value.id}`, { status: 'invalid' });
    batch.value = r.data;
    rows.value = r.data.rows ?? [];
    if (!['confirmed', 'processing', 'validating', 'uploaded'].includes(batch.value!.status)) {
        clearInterval(poll);
    }
}

async function upload() {
    if (!file.value || !type.value) {
        return;
    }
    busy.value = true;
    error.value = null;
    try {
        const form = new FormData();
        form.append('file', file.value);
        batch.value = (await api(`/api/v1/imports/${type.value}`, { method: 'POST', form })).data;
        await refresh();
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

async function confirm() {
    busy.value = true;
    try {
        await post(`/api/v1/imports/${batch.value!.id}/confirm`);
        notify('Import started; this page updates when it finishes.', 'info');
        clearInterval(poll);
        poll = setInterval(() => void refresh(), 3000);
        await refresh();
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

async function cancel() {
    await post(`/api/v1/imports/${batch.value!.id}/cancel`);
    batch.value = null;
    rows.value = [];
}

async function errorFile() {
    const r = await post(`/api/v1/imports/${batch.value!.id}/errors`);
    await download(`/api/v1/files/${r.data.file_id}`, r.data.filename);
}
</script>

<template>
    <PageHeader title="Imports" subtitle="Upload CSV or Excel files; nothing is written until you confirm the preview" />
    <ErrorSummary :error="error" />

    <section class="card mb-6">
        <form class="grid gap-4 md:grid-cols-[16rem_1fr_auto] md:items-end" @submit.prevent="upload">
            <div>
                <label for="import-type" class="label">What to import</label>
                <select id="import-type" v-model="type" class="input">
                    <option v-for="t in allowedTypes" :key="t.type" :value="t.type">{{ t.label }}</option>
                </select>
            </div>
            <div>
                <label for="import-file" class="label">File (CSV or XLSX, up to 50 MB)</label>
                <input id="import-file" type="file" class="input py-2" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                       @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null">
            </div>
            <div class="flex gap-2">
                <UiButton v-if="type" icon="download" @click="download(`/api/v1/imports/templates/${type}`, `${type}-template.csv`)">Template</UiButton>
                <UiButton type="submit" variant="primary" icon="upload" :loading="busy" :disabled="!file || !type">Upload</UiButton>
            </div>
        </form>
        <p v-if="types.length && !allowedTypes.length" class="help">You do not have permission for any import type.</p>
    </section>

    <section v-if="batch" class="card">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h2 class="text-lg font-semibold">{{ batch.original_filename }}</h2>
            <StatusBadge :value="batch.status" :label="batch.status_label" />
            <span class="text-sm text-ink-muted">{{ batch.row_count }} rows · {{ batch.valid_count }} valid · {{ batch.invalid_count }} with problems
                <template v-if="batch.imported_count"> · {{ batch.imported_count }} imported</template></span>
            <div class="ml-auto flex gap-2">
                <UiButton v-if="batch.invalid_count" icon="download" @click="errorFile">Error file</UiButton>
                <UiButton v-if="batch.status === 'previewed'" @click="cancel">Discard</UiButton>
                <UiButton v-if="batch.status === 'previewed'" variant="primary" :loading="busy" :disabled="!batch.valid_count" @click="confirm">
                    Import {{ batch.valid_count }} valid rows
                </UiButton>
            </div>
        </div>
        <h3 v-if="rows.length" class="mb-2 font-semibold">Rows with problems</h3>
        <DataTable :columns="rowColumns" :rows="rows" empty="No problems found." caption="Rows with problems" />
    </section>
</template>
