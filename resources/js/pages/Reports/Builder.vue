<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import EnumSelect from '@/components/ui/EnumSelect.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import { ApiError, get, patch, post } from '@/lib/api';
import { enumLabel, loadEnums } from '@/lib/meta';
import { formatDate, formatDateTime, formatMoney, formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';
import type { NextAction } from '@/types/shared';

/**
 * Custom report builder and viewer (Chapter 10 §10.8): pick a dataset you may read, join related
 * datasets, choose fields, filter, group and aggregate, sort, preview on screen, save / share, and
 * export to PDF, Excel or CSV (each format has its own permission). The server compiles and scopes
 * every query; this screen only edits the definition.
 */
const props = defineProps<{ definitionId: string | null }>();
const { can } = useShared();

interface Field { code: string; label: string; type: string; enum: string | null; allowed: boolean; aggregatable: boolean; groupable: boolean; filterable: boolean; sortable: boolean }
interface Dataset { code: string; label: string; description: string | null; fields: Field[]; relationships: Array<{ code: string; label: string; target: string; cardinality: string }> }
interface Condition { field: string; operator: string; value: unknown; value2?: unknown }

const datasets = ref<Dataset[]>([]);
const saved = ref<Record<string, any> | null>(null);
const datasetCode = ref('');
const joins = ref<string[]>([]);
const fields = ref<string[]>([]);
const conditions = ref<Condition[]>([]);
const retainedFilters = ref<unknown>(null);
const groupBy = ref<string[]>([]);
const aggregates = ref<Array<{ fn: string; field?: string; as: string }>>([]);
const sort = ref<Array<{ field: string; dir: 'asc' | 'desc' }>>([]);
const limit = ref<number | null>(null);
const parameters = ref<Array<Record<string, any>>>([]);
const paramValues = reactive<Record<string, unknown>>({});
const meta = reactive({ name: '', description: '', tags: '' });

const result = ref<Record<string, any> | null>(null);
const page = ref(1);
const running = ref(false);
const saving = ref(false);
const exporting = ref<string | null>(null);
const error = ref<unknown>(null);
const shareOpen = ref(false);
const share = reactive<{ visibility: string | null; roles: string[] }>({ visibility: null, roles: [] });
const roles = ref<Array<Record<string, any>>>([]);
let pollTimer: ReturnType<typeof setTimeout> | undefined;

const dataset = computed(() => datasets.value.find((d) => d.code === datasetCode.value) ?? null);
const relationships = computed(() => (dataset.value?.relationships ?? []).filter((r) => r.cardinality !== 'one_to_many' && datasets.value.some((d) => d.code === r.target)));
const available = computed<Array<Field & { ref: string; group: string }>>(() => {
    if (!dataset.value) {
        return [];
    }
    const base = dataset.value.fields.filter((f) => f.allowed).map((f) => ({ ...f, ref: f.code, group: dataset.value!.label }));
    const joined = joins.value.flatMap((code) => {
        const rel = dataset.value!.relationships.find((r) => r.code === code);
        const target = datasets.value.find((d) => d.code === rel?.target);

        return (target?.fields ?? []).filter((f) => f.allowed).map((f) => ({ ...f, ref: `${code}.${f.code}`, label: `${rel!.label}: ${f.label}`, group: rel!.label }));
    });

    return [...base, ...joined];
});
const fieldByRef = computed(() => Object.fromEntries(available.value.map((f) => [f.ref, f])));
const readonly = computed(() => !!saved.value && (saved.value.is_system_seed || !can('reporting.definition.update')));
const formats = computed(() => (['pdf', 'xlsx', 'csv'] as const).filter((f) => can(`reporting.report.export_${f}`)));
const archiving = ref(false);
const canArchive = computed(() => !!saved.value && !saved.value.is_system_seed && can('reporting.definition.archive'));
/** Next action, step by step: choose the data, run it, keep it, share or export it. */
const nextActions = computed<NextAction[]>(() => {
    if (!datasetCode.value) {
        return [{ label: 'Choose what to report on', icon: 'list', onClick: () => document.getElementById('rb-dataset')?.focus(), hint: 'Pick a dataset; its fields and filters appear below.' }];
    }
    if (!result.value) {
        return [{ label: 'Run the report', icon: 'chart', onClick: () => void run(), hint: 'See the live result before you save or export it.' }];
    }
    const keep = (!saved.value || dirty()) && can('reporting.definition.create') && !readonly.value;
    const exportFirst = formats.value[0];

    return [
        ...(keep ? [{ label: saved.value ? 'Save changes' : 'Save the report', icon: 'check', onClick: () => document.getElementById('rb-name')?.focus(),
            hint: 'Give it a name below and save it to run it again any time.' }] : []),
        ...(exportFirst ? [{ label: `Export to ${exportFirst === 'xlsx' ? 'Excel' : exportFirst.toUpperCase()}`, icon: 'download', onClick: () => void exportAs(exportFirst),
            hint: keep ? undefined : 'Exports run in the background and download when ready.' }] : []),
    ];
});

async function archive() {
    if (!saved.value || !window.confirm(`Archive “${saved.value.name}”? It disappears from the report list for everyone.`)) {
        return;
    }
    archiving.value = true;
    error.value = null;
    try {
        await post(`/api/v1/report-definitions/${saved.value.id}/archive`, { reason: null });
        notify('Report archived.');
        router.visit('/reports');
    } catch (e) {
        error.value = e;
    } finally {
        archiving.value = false;
    }
}

const OPERATORS: Record<string, string[]> = {
    string: ['eq', 'neq', 'contains', 'starts_with', 'ends_with', 'in', 'is_empty', 'is_not_empty'],
    enum: ['eq', 'neq', 'in', 'not_in', 'is_empty', 'is_not_empty'],
    uuid: ['eq', 'neq', 'is_empty', 'is_not_empty'],
    integer: ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'],
    decimal: ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'],
    money: ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'],
    quantity: ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'],
    date: ['eq', 'gt', 'gte', 'lt', 'lte', 'between', 'relative_date', 'is_empty', 'is_not_empty'],
    datetime: ['gt', 'gte', 'lt', 'lte', 'between', 'relative_date'],
    boolean: ['eq'],
};
const operatorsFor = (ref: string) => OPERATORS[fieldByRef.value[ref]?.type ?? 'string'] ?? OPERATORS.string;

function definition(): Record<string, unknown> {
    const conds = conditions.value.filter((c) => c.field && c.operator).map((c) => {
        if (['is_empty', 'is_not_empty'].includes(c.operator)) {
            return { field: c.field, operator: c.operator };
        }

        return { field: c.field, operator: c.operator, value: c.operator === 'between' ? [c.value, c.value2] : c.value };
    });
    const filters = retainedFilters.value
        ? { op: 'and', conditions: [retainedFilters.value, ...conds] }
        : conds.length ? { op: 'and', conditions: conds } : undefined;

    return Object.fromEntries(Object.entries({
        fields: fields.value.length ? fields.value : undefined,
        joins: joins.value.length ? joins.value : undefined,
        filters,
        parameters: parameters.value.length ? parameters.value : undefined,
        group_by: groupBy.value.length ? groupBy.value : undefined,
        aggregates: aggregates.value.length ? aggregates.value : undefined,
        sort: sort.value.length ? sort.value : undefined,
        limit: limit.value ?? undefined,
    }).filter(([, v]) => v !== undefined));
}

function applyDefinition(d: Record<string, any>) {
    joins.value = [...(d.joins ?? [])];
    fields.value = (d.fields ?? []).map((f: any) => (typeof f === 'string' ? f : f.field)).filter(Boolean);
    groupBy.value = [...(d.group_by ?? [])];
    aggregates.value = (d.aggregates ?? []).filter((a: any) => !a.expr);
    sort.value = [...(d.sort ?? [])];
    limit.value = d.limit ?? null;
    parameters.value = [...(d.parameters ?? [])];
    for (const p of parameters.value) {
        paramValues[p.code] = p.default ?? null;
    }
    const top = d.filters;
    const simple = top?.op === 'and' && Array.isArray(top.conditions)
        && top.conditions.every((c: any) => c.field && !c.op && !(c.value && typeof c.value === 'object' && !Array.isArray(c.value)));
    if (top && simple) {
        conditions.value = top.conditions.map((c: any) => (c.operator === 'between' ? { field: c.field, operator: c.operator, value: c.value?.[0], value2: c.value?.[1] }
            : { field: c.field, operator: c.operator, value: c.value }));
    } else {
        retainedFilters.value = top ?? null;
    }
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    try {
        datasets.value = (await get('/api/v1/reporting/catalog')).data;
        if (props.definitionId) {
            saved.value = (await get(`/api/v1/report-definitions/${props.definitionId}`)).data;
            datasetCode.value = saved.value!.dataset_code;
            meta.name = saved.value!.name;
            meta.description = saved.value!.description ?? '';
            meta.tags = (saved.value!.tags ?? []).join(', ');
            applyDefinition(saved.value!.definition ?? {});
            if (!parameters.value.some((p) => p.required && paramValues[p.code] === null)) {
                await run(1);
            }
        } else {
            datasetCode.value = new URLSearchParams(window.location.search).get('dataset') ?? '';
        }
    } catch (e) {
        error.value = e;
    }
});
onBeforeUnmount(() => clearTimeout(pollTimer));

function chooseDataset(code: string) {
    datasetCode.value = code;
    joins.value = [];
    fields.value = (datasets.value.find((d) => d.code === code)?.fields ?? []).filter((f) => f.allowed).slice(0, 6).map((f) => f.code);
    conditions.value = [];
    retainedFilters.value = null;
    groupBy.value = [];
    aggregates.value = [];
    sort.value = [];
    result.value = null;
}

function toggle(list: string[], value: string) {
    const i = list.indexOf(value);
    i >= 0 ? list.splice(i, 1) : list.push(value);
}

async function run(p = 1) {
    if (!datasetCode.value) {
        return;
    }
    running.value = true;
    error.value = null;
    page.value = p;
    try {
        const params = Object.fromEntries(Object.entries(paramValues).filter(([, v]) => v !== null && v !== ''));
        result.value = saved.value && !dirty()
            ? (await post(`/api/v1/report-definitions/${saved.value.id}/run`, { parameters: params, page: p, per_page: 100 })).data
            : (await post('/api/v1/reports/preview', { dataset_code: datasetCode.value, definition: definition(), parameters: params, per_page: 200 })).data;
    } catch (e) {
        error.value = e;
    } finally {
        running.value = false;
    }
}

function dirty(): boolean {
    return !!saved.value && JSON.stringify(saved.value.definition ?? {}) !== JSON.stringify(definition());
}

async function save(asCopy = false) {
    saving.value = true;
    error.value = null;
    const body = { name: meta.name, description: meta.description || null, dataset_code: datasetCode.value, definition: definition(),
        tags: meta.tags.split(',').map((t) => t.trim()).filter(Boolean) };
    try {
        let id: string;
        if (asCopy && saved.value) {
            id = (await post(`/api/v1/report-definitions/${saved.value.id}/clone`, { name: meta.name || `${saved.value.name} (copy)` })).data.id;
            if (dirty()) {
                await patch(`/api/v1/report-definitions/${id}`, body);
            }
        } else if (saved.value) {
            id = (await patch(`/api/v1/report-definitions/${saved.value.id}`, { ...body, version: saved.value.definition_version })).data.id;
        } else {
            id = (await post('/api/v1/report-definitions', body)).data.id;
        }
        notify('Report saved.');
        router.visit(`/reports/${id}`);
    } catch (e) {
        error.value = e;
    } finally {
        saving.value = false;
    }
}

async function exportAs(format: string) {
    exporting.value = format;
    error.value = null;
    try {
        const params = Object.fromEntries(Object.entries(paramValues).filter(([, v]) => v !== null && v !== ''));
        const target = saved.value && !dirty() ? saved.value.id : 'draft';
        const body = target === 'draft' ? { dataset_code: datasetCode.value, definition: definition(), parameters: params } : { parameters: params };
        const r = (await post(`/api/v1/report-definitions/${target}/exports/${format}`, body)).data;
        notify('Export queued — it downloads when ready.', 'info');
        await pollRun(r.id);
    } catch (e) {
        error.value = e;
        exporting.value = null;
    }
}

async function pollRun(id: string) {
    const run = (await get(`/api/v1/report-runs/${id}`)).data;
    if (run.status === 'completed' && run.download_url) {
        exporting.value = null;
        window.location.assign(run.download_url);
    } else if (['failed', 'cancelled', 'rejected', 'expired'].includes(run.status)) {
        exporting.value = null;
        error.value = new ApiError(422, { message: run.error_message ?? `The export ${run.status}.`, code: run.error_code });
    } else {
        pollTimer = setTimeout(() => void pollRun(id), 2000);
    }
}

async function openShare() {
    share.visibility = saved.value?.visibility ?? 'private';
    share.roles = [...(saved.value?.shared_role_ids ?? [])];
    roles.value = (await get('/api/v1/roles', { per_page: 100 }).catch(() => ({ data: [] }))).data;
    shareOpen.value = true;
}

async function saveShare() {
    try {
        saved.value = (await post(`/api/v1/report-definitions/${saved.value!.id}/share`, { visibility: share.visibility, shared_role_ids: share.roles })).data;
        shareOpen.value = false;
        notify('Sharing updated.');
    } catch (e) {
        error.value = e;
    }
}

function cell(value: unknown, column: Record<string, any>): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    switch (column.type) {
        case 'enum':
            return enumLabel(column.enum ?? undefined, value);
        case 'date':
            return formatDate(value);
        case 'datetime':
            return formatDateTime(value);
        case 'money':
            return formatMoney(value);
        case 'quantity':
        case 'decimal':
            return formatQuantity(value);
        case 'boolean':
            return value ? 'Yes' : 'No';
        default:
            return typeof value === 'object' ? JSON.stringify(value) : String(value);
    }
}
</script>

<template>
    <PageHeader :title="saved ? saved.name : 'New report'" :subtitle="dataset?.label" back="/reports" :next="nextActions">
        <template #actions>
            <UiButton v-if="canArchive" variant="ghost" icon="x" :loading="archiving" @click="archive">Archive</UiButton>
            <UiButton v-if="saved && can('reporting.definition.share') && !saved.is_system_seed" icon="users" @click="openShare">Share</UiButton>
            <UiButton v-for="f in formats" :key="f" icon="download" :loading="exporting === f" :disabled="!datasetCode || exporting !== null" @click="exportAs(f)">
                {{ f === 'xlsx' ? 'Excel' : f.toUpperCase() }}
            </UiButton>
        </template>
    </PageHeader>
    <ErrorSummary :error="error" />

    <div class="grid gap-6 xl:grid-cols-[24rem_1fr]">
        <aside class="space-y-4">
            <section class="card space-y-3">
                <label for="rb-dataset" class="label">Dataset</label>
                <select id="rb-dataset" :value="datasetCode" class="input" :disabled="!!saved" @change="chooseDataset(($event.target as HTMLSelectElement).value)">
                    <option value="" disabled>Choose what to report on…</option>
                    <option v-for="d in datasets" :key="d.code" :value="d.code">{{ d.label }}</option>
                </select>
                <p v-if="dataset?.description" class="help">{{ dataset.description }}</p>
                <div v-if="relationships.length">
                    <p class="label">Include related</p>
                    <label v-for="r in relationships" :key="r.code" class="flex min-h-9 items-center gap-2 text-sm">
                        <input type="checkbox" class="size-4 accent-brand" :checked="joins.includes(r.code)" :disabled="readonly" @change="toggle(joins, r.code)"> {{ r.label }}
                    </label>
                </div>
            </section>

            <section v-if="dataset" class="card">
                <p class="label">Columns</p>
                <div class="max-h-72 space-y-1 overflow-y-auto pr-1">
                    <label v-for="f in available" :key="f.ref" class="flex min-h-9 items-center gap-2 text-sm">
                        <input type="checkbox" class="size-4 accent-brand" :checked="fields.includes(f.ref)" :disabled="readonly" @change="toggle(fields, f.ref)"> {{ f.label }}
                    </label>
                </div>
            </section>

            <section v-if="dataset" class="card space-y-3">
                <p class="label">Filters</p>
                <p v-if="retainedFilters" class="help">Advanced filters from the saved definition are kept as they are.</p>
                <div v-for="(c, i) in conditions" :key="i" class="space-y-2 rounded-brand bg-surface-muted p-2">
                    <select v-model="c.field" class="input" :aria-label="`Filter ${i + 1} field`" :disabled="readonly">
                        <option v-for="f in available.filter((x) => x.filterable)" :key="f.ref" :value="f.ref">{{ f.label }}</option>
                    </select>
                    <select v-model="c.operator" class="input" :aria-label="`Filter ${i + 1} operator`" :disabled="readonly">
                        <option v-for="o in operatorsFor(c.field)" :key="o" :value="o">{{ enumLabel('report_filter_operator', o) }}</option>
                    </select>
                    <template v-if="!['is_empty', 'is_not_empty'].includes(c.operator)">
                        <EnumSelect
                            v-if="c.operator === 'relative_date'"
                            :id="`rf-${i}`"
                            enum-code="relative_date_range"
                            :model-value="c.value as string | null"
                            @update:model-value="c.value = $event"
                        />
                        <EnumSelect
                            v-else-if="fieldByRef[c.field]?.type === 'enum' && fieldByRef[c.field]?.enum"
                            :id="`rf-${i}`"
                            :enum-code="fieldByRef[c.field].enum!"
                            :multiple="['in', 'not_in'].includes(c.operator)"
                            :model-value="c.value as string | string[] | null"
                            @update:model-value="c.value = $event"
                        />
                        <select v-else-if="fieldByRef[c.field]?.type === 'boolean'" v-model="c.value" class="input" :aria-label="`Filter ${i + 1} value`">
                            <option :value="true">Yes</option>
                            <option :value="false">No</option>
                        </select>
                        <div v-else class="flex gap-2">
                            <input v-model="c.value" class="input" :type="['date'].includes(fieldByRef[c.field]?.type ?? '') ? 'date' : 'text'" :aria-label="`Filter ${i + 1} value`">
                            <input v-if="c.operator === 'between'" v-model="c.value2" class="input" :type="fieldByRef[c.field]?.type === 'date' ? 'date' : 'text'" :aria-label="`Filter ${i + 1} second value`">
                        </div>
                    </template>
                    <button v-if="!readonly" type="button" class="text-sm text-danger underline" @click="conditions.splice(i, 1)">Remove filter</button>
                </div>
                <UiButton v-if="!readonly" icon="filter" @click="conditions.push({ field: available.find((f) => f.filterable)?.ref ?? '', operator: 'eq', value: null })">Add filter</UiButton>
            </section>

            <section v-if="dataset" class="card space-y-3">
                <p class="label">Group & total</p>
                <div class="flex flex-wrap gap-2">
                    <label v-for="f in available.filter((x) => x.groupable)" :key="f.ref" class="flex min-h-9 items-center gap-1 rounded-full bg-surface-muted px-3 text-sm">
                        <input type="checkbox" class="size-4 accent-brand" :checked="groupBy.includes(f.ref)" :disabled="readonly" @change="toggle(groupBy, f.ref)"> {{ f.label }}
                    </label>
                </div>
                <div v-for="(a, i) in aggregates" :key="i" class="flex gap-2">
                    <select v-model="a.fn" class="input w-36" :aria-label="`Total ${i + 1} function`" :disabled="readonly">
                        <option v-for="fn in ['count', 'count_distinct', 'sum', 'avg', 'min', 'max']" :key="fn" :value="fn">{{ enumLabel('report_aggregate', fn) }}</option>
                    </select>
                    <select v-if="a.fn !== 'count'" v-model="a.field" class="input" :aria-label="`Total ${i + 1} field`" :disabled="readonly"
                            @change="a.as = `${a.fn}_${(a.field ?? '').replace('.', '_')}`">
                        <option v-for="f in available.filter((x) => x.aggregatable || a.fn.startsWith('count'))" :key="f.ref" :value="f.ref">{{ f.label }}</option>
                    </select>
                    <button v-if="!readonly" type="button" class="btn btn-ghost px-2 text-danger" aria-label="Remove total" @click="aggregates.splice(i, 1)">✕</button>
                </div>
                <UiButton v-if="!readonly" @click="aggregates.push({ fn: 'count', as: `count_${aggregates.length + 1}` })">Add total</UiButton>
            </section>

            <section v-if="dataset" class="card space-y-2">
                <p class="label">Sort</p>
                <div v-for="(s, i) in sort" :key="i" class="flex gap-2">
                    <select v-model="s.field" class="input" :aria-label="`Sort ${i + 1} field`" :disabled="readonly">
                        <option v-for="f in [...available.filter((x) => x.sortable).map((x) => ({ ref: x.ref, label: x.label })), ...aggregates.map((a) => ({ ref: a.as, label: a.as }))]"
                                :key="f.ref" :value="f.ref">{{ f.label }}</option>
                    </select>
                    <select v-model="s.dir" class="input w-28" :aria-label="`Sort ${i + 1} direction`" :disabled="readonly">
                        <option value="asc">A→Z</option>
                        <option value="desc">Z→A</option>
                    </select>
                    <button v-if="!readonly" type="button" class="btn btn-ghost px-2 text-danger" aria-label="Remove sort" @click="sort.splice(i, 1)">✕</button>
                </div>
                <UiButton v-if="!readonly" @click="sort.push({ field: fields[0] ?? '', dir: 'asc' })">Add sort</UiButton>
            </section>

            <section v-if="dataset && (can('reporting.definition.create') || saved)" class="card space-y-3">
                <div>
                    <label for="rb-name" class="label">Report name</label>
                    <input id="rb-name" v-model="meta.name" class="input" :disabled="readonly && !can('reporting.definition.clone')">
                </div>
                <div>
                    <label for="rb-desc" class="label">Description</label>
                    <textarea id="rb-desc" v-model="meta.description" class="input min-h-20" :disabled="readonly" />
                </div>
                <div>
                    <label for="rb-tags" class="label">Tags (comma separated)</label>
                    <input id="rb-tags" v-model="meta.tags" class="input" :disabled="readonly">
                </div>
                <div class="flex flex-wrap gap-2">
                    <UiButton v-if="!readonly && (saved ? can('reporting.definition.update') : can('reporting.definition.create'))" variant="primary" :loading="saving"
                              :disabled="!meta.name || !datasetCode" @click="save(false)">Save</UiButton>
                    <UiButton v-if="saved && can('reporting.definition.clone')" :loading="saving" @click="save(true)">Save as copy</UiButton>
                </div>
            </section>
        </aside>

        <section class="min-w-0 space-y-4">
            <div v-if="parameters.length" class="card grid gap-3 md:grid-cols-3">
                <div v-for="p in parameters" :key="p.code">
                    <label :for="`rp-${p.code}`" class="label">{{ p.label ?? p.code }}<span v-if="p.required" class="text-danger"> *</span></label>
                    <EnumSelect v-if="p.type === 'enum' && p.enum" :id="`rp-${p.code}`" :enum-code="p.enum" :model-value="paramValues[p.code] as string | null"
                                nullable @update:model-value="paramValues[p.code] = $event" />
                    <input v-else :id="`rp-${p.code}`" v-model="paramValues[p.code]" class="input" :type="p.type === 'date' ? 'date' : 'text'">
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <UiButton variant="accent" :loading="running" :disabled="!datasetCode" @click="run(1)">Run</UiButton>
                <span v-if="result" class="text-sm text-ink-muted">
                    {{ result.rows.length }} rows<template v-if="result.has_more">+</template> · {{ result.duration_ms }} ms
                    <template v-if="result.truncated"> · limited to {{ result.row_cap }} rows on screen — export for all rows</template>
                </span>
            </div>
            <div v-if="result" class="card overflow-x-auto p-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th v-for="c in result.columns" :key="c.key" scope="col" :class="['money', 'quantity', 'decimal', 'integer'].includes(c.type) ? 'text-right' : ''">{{ c.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in result.rows" :key="i">
                            <td v-for="c in result.columns" :key="c.key" :class="['money', 'quantity', 'decimal', 'integer'].includes(c.type) ? 'text-right tabular-nums' : ''">
                                {{ cell(row[c.key], c) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="result.rows.length === 0" class="p-6 text-center text-sm text-ink-muted">No rows match.</p>
            </div>
            <div v-if="result && saved && !dirty() && (page > 1 || result.has_more)" class="flex justify-center gap-2">
                <UiButton :disabled="page <= 1 || running" @click="run(page - 1)">Previous</UiButton>
                <UiButton :disabled="!result.has_more || running" @click="run(page + 1)">Next</UiButton>
            </div>
        </section>
    </div>

    <ModalDialog :open="shareOpen" title="Share report" @close="shareOpen = false">
        <div class="space-y-4">
            <div>
                <label for="share-visibility" class="label">Who can open it</label>
                <EnumSelect id="share-visibility" v-model="share.visibility" enum-code="report_visibility" />
            </div>
            <fieldset v-if="share.visibility === 'shared_roles'">
                <legend class="label">Roles</legend>
                <label v-for="r in roles" :key="r.id" class="flex min-h-9 items-center gap-2 text-sm">
                    <input type="checkbox" class="size-4 accent-brand" :checked="share.roles.includes(r.id)" @change="toggle(share.roles, r.id)"> {{ r.name }}
                </label>
            </fieldset>
        </div>
        <template #footer>
            <UiButton @click="shareOpen = false">Cancel</UiButton>
            <UiButton variant="primary" @click="saveShare">Save</UiButton>
        </template>
    </ModalDialog>
</template>
