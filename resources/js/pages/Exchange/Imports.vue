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
import type { NextAction } from '@/types/shared';

/**
 * CSV / XLSX imports (Chapter 11 §11.9): upload → validated preview with per-row errors →
 * confirm (processed in the background) → error file. Each import type has its own permission.
 * `?batch=<id>` (the "import finished" notification) opens that import's result. Next action follows
 * the same steps: template → upload → confirm → check the imported records.
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
/** Where the records of each import type are looked after. */
const IMPORTED_INTO: Record<string, string> = {
    items: 'items', variants: 'item-variants', categories: 'item-categories', uom: 'units', barcodes: 'items', packagings: 'items',
    vendor_pricelists: 'vendor-pricelists', reorder_rules: 'reorder-rules', branches: 'branches', warehouses: 'warehouses',
    storage_locations: 'storage-locations', partners: 'partners', lots: 'lots', opening_balances: 'opening-balances', roles: 'roles',
};
const nextActions = computed<NextAction[]>(() => {
    const b = batch.value;
    if (b?.status === 'previewed') {
        return [
            ...(b.valid_count ? [{ label: `Import ${b.valid_count} valid rows`, icon: 'upload', onClick: () => void confirm(),
                hint: b.invalid_count ? `${b.invalid_count} row(s) have problems and will be skipped — fix them from the error file.` : 'Every row is valid.' }] : []),
            ...(b.invalid_count ? [{ label: 'Download the error file', icon: 'download', onClick: () => void errorFile() }] : []),
        ];
    }
    if (b?.status === 'completed' || b?.status === 'completed_with_errors') {
        const into = IMPORTED_INTO[b.import_type ?? type.value];

        return [
            ...(into ? [{ label: 'Check the imported records', icon: 'list', href: `/r/${into}`, hint: `${b.imported_count ?? 0} row(s) imported.` }] : []),
            { label: 'Import another file', icon: 'upload', onClick: () => { batch.value = null; rows.value = []; } },
        ];
    }
    if (!b && type.value) {
        const label = allowedTypes.value.find((t) => t.type === type.value)?.label ?? type.value;

        return [{ label: `Download the ${label.toLowerCase()} template`, icon: 'download', onClick: () => void download(`/api/v1/imports/templates/${type.value}`, `${type.value}-template.csv`),
            hint: 'Fill in the template, then upload it. Nothing is written until you confirm the preview.' }];
    }

    return [];
});
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
        const linked = new URLSearchParams(window.location.search).get('batch');
        if (linked) {
            batch.value = { id: linked };
            await refresh();
            if (['confirmed', 'processing', 'validating', 'uploaded'].includes(batch.value?.status)) {
                poll = setInterval(() => void refresh(), 3000);
            }
        }
    } catch (e) {
        batch.value = null;
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
    <PageHeader title="Imports" subtitle="Upload CSV or Excel files; nothing is written until you confirm the preview" :next="nextActions" />
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
