<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import ActionDialog from '@/components/ActionDialog.vue';
import ItemImagesPanel from '@/components/ItemImagesPanel.vue';
import WorkflowStepper from '@/components/WorkflowStepper.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import { actionsFor, followUps, isEditable, taskHref } from '@/lib/workflow';
import { download, get } from '@/lib/api';
import { loadEnums, loadSchema, propertyLabel, type SchemaProperty } from '@/lib/meta';
import { columnsFor, displayValue, PRINT_TYPES } from '@/lib/display';
import { notify } from '@/lib/toast';
import type { ResourceAction, ResourceMeta } from '@/types/shared';

/**
 * Generic record page: fields, lines, related records, read panels (ResourceRegistry `panels`) and
 * the "Next action": the workflow's next steps for this status, then the hand-offs that usually follow
 * (WorkflowCatalog::NEXT), and — once nothing is left to do here — the way back to the work.
 */
const props = defineProps<{ resource: ResourceMeta; id: string }>();

const record = ref<Record<string, any> | null>(null);
const properties = ref<Record<string, SchemaProperty>>({});
const lineProperties = ref<Record<string, SchemaProperty>>({});
const error = ref<unknown>(null);
const active = ref<ResourceAction | null>(null);
const panelRows = ref<Record<string, Record<string, any>[]>>({});

const title = computed(() => {
    const r = record.value;

    return r ? String(r.document_number ?? r.sku ?? r.code ?? r.lot_number ?? r.serial_number ?? r.movement_number ?? r.username ?? r.name ?? props.resource.label) : props.resource.label;
});
const fields = computed(() => {
    const r = record.value ?? {};
    const keys = Object.keys(properties.value).length ? Object.keys(properties.value) : Object.keys(r);

    return keys.filter((k) => k !== 'id' && !k.endsWith('_label') && !Array.isArray(r[k]) && (typeof r[k] !== 'object' || r[k] === null || properties.value[k])
        && !properties.value[k]?.sensitive && r[k] !== undefined && !(properties.value[k]?.type === 'array' && properties.value[k]?.items));
});
const lines = computed(() => (Array.isArray(record.value?.lines) ? record.value!.lines as Record<string, any>[] : []));
const related = computed(() => props.resource.relations.filter((r) => r !== 'lines' && Array.isArray(record.value?.[r]) && record.value![r].length));
const printType = computed(() => PRINT_TYPES[props.resource.key]);
/** Relations that are private files, downloaded through their resource's authorized route. */
const FILE_RELATIONS: Record<string, string> = { 'payment-confirmations': 'evidence' };
async function openFile(file: { id: string; original_filename: string }) {
    try {
        await download(`${props.resource.api}/${props.id}/${FILE_RELATIONS[props.resource.key]}/${file.id}`, file.original_filename);
    } catch (e) {
        error.value = e;
    }
}
/** Warehouse-floor actions open their scan-first task screen instead of a form dialog (WorkflowCatalog `task`). */
const taskOf = (a: ResourceAction) => taskHref(props.resource.workflow, a, props.id);
/** Only what applies in the record's current status, primary next steps first; the server re-checks everything. */
const next = computed(() => actionsFor(props.resource.actions, props.resource.workflow, record.value?.status));
const canEdit = computed(() => props.resource.can_update && isEditable(props.resource.workflow, record.value?.status));
const handOffs = computed(() => (record.value ? followUps(props.resource.next, record.value) : []));
/** Nothing left to do on this record: point back to where the work continues. */
const backToWork = computed(() => (props.resource.hub ? { label: `Back to ${props.resource.hub.label}`, href: `/work/${props.resource.hub.key}` }
    : { label: `All ${props.resource.label.toLowerCase()}`, href: `/r/${props.resource.key}` }));
const DANGER = /(cancel|reject|archive|blacklist|close|lock|deactivate|suspend|revoke|void)/;

async function load() {
    try {
        record.value = (await get(`${props.resource.api}/${props.id}`)).data;
    } catch (e) {
        error.value = e;
    }
    await Promise.all(props.resource.panels.map(async (panel) => {
        try {
            const data = (await get(panel.uri.replace(/\{[a-zA-Z]+\}/g, encodeURIComponent(props.id)))).data;
            panelRows.value[panel.label] = Array.isArray(data) ? data : data ? [data] : [];
        } catch {
            panelRows.value[panel.label] = [];
        }
    }));
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    if (props.resource.schema) {
        const schema = await loadSchema(props.resource.schema);
        properties.value = schema.properties;
        const linesProp = schema.properties.lines;
        if (linesProp?.items) {
            lineProperties.value = (await loadSchema(linesProp.items)).properties;
        }
    }
    await load();
});

async function done() {
    notify(`${active.value?.label ?? 'Action'} completed.`);
    active.value = null;
    await load();
}

async function print() {
    try {
        await download(`/api/v1/print/${printType.value}/${props.id}`, `${title.value}.pdf`);
    } catch (e) {
        error.value = e;
    }
}
</script>

<template>
    <PageHeader :title="title" :subtitle="resource.label" :back="`/r/${resource.key}`">
        <template #actions>
            <UiButton v-if="printType" icon="printer" @click="print">Print</UiButton>
            <UiButton v-if="canEdit" icon="edit" :href="`/r/${resource.key}/${id}/edit`">Edit</UiButton>
        </template>
    </PageHeader>
    <ErrorSummary :error="error" />

    <div v-if="record" class="space-y-6">
        <WorkflowStepper v-if="resource.workflow" :workflow="resource.workflow" :status="record.status" :status-label="record.status_label" />

        <!-- Next action: documents' next step(s) for this status, other resources' permitted actions, then the hand-offs. -->
        <section class="card border-l-4 border-l-brand" aria-labelledby="next-title">
            <h2 id="next-title" class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">Next action</h2>
            <p v-if="resource.workflow && !next.primary.length && handOffs.length" class="mb-3 text-sm">This {{ resource.noun }} is done here. What usually follows:</p>
            <p v-else-if="!next.primary.length && !next.more.length && !handOffs.length" class="mb-3 text-sm text-ink-muted">Nothing is waiting on this {{ resource.noun }}.</p>
            <div class="flex flex-wrap items-center gap-2">
                <template v-for="(a, i) in next.primary" :key="a.uri + a.method">
                    <UiButton v-if="taskOf(a)" :variant="i === 0 ? 'primary' : 'secondary'" icon="scan" :href="taskOf(a)!">{{ a.label }}</UiButton>
                    <UiButton v-else :variant="i === 0 ? 'primary' : 'secondary'" @click="active = a">{{ a.label }}</UiButton>
                </template>
                <UiButton
                    v-for="(n, i) in handOffs"
                    :key="n.href"
                    :variant="!next.primary.length && i === 0 ? 'primary' : 'secondary'"
                    :icon="n.icon"
                    :href="n.href"
                >
                    {{ n.label }}
                </UiButton>
                <template v-if="!resource.workflow">
                    <UiButton v-for="a in next.more" :key="a.uri + a.method" :variant="DANGER.test(a.name) ? 'secondary' : 'primary'" @click="active = a">
                        {{ a.label }}
                    </UiButton>
                </template>
                <details v-else-if="next.more.length" class="relative">
                    <summary class="btn btn-ghost list-none [&::-webkit-details-marker]:hidden">More <AppIcon name="chevron-down" :size="16" /></summary>
                    <div class="absolute left-0 z-10 mt-1 w-60 rounded-brand border border-line bg-surface py-1 shadow-lg" role="menu">
                        <template v-for="a in next.more" :key="a.uri + a.method">
                            <a v-if="taskOf(a)" :href="taskOf(a)!" class="flex min-h-11 items-center px-4 text-sm hover:bg-surface-muted" role="menuitem">{{ a.label }}</a>
                            <button
                                v-else
                                type="button"
                                :class="['flex min-h-11 w-full items-center px-4 text-left text-sm hover:bg-surface-muted', DANGER.test(a.name) ? 'text-danger' : '']"
                                role="menuitem"
                                @click="active = a"
                            >
                                {{ a.label }}
                            </button>
                        </template>
                    </div>
                </details>
                <UiButton v-if="!next.primary.length && !handOffs.length" variant="ghost" icon="arrow-right" :href="backToWork.href">{{ backToWork.label }}</UiButton>
            </div>
        </section>

        <section class="card">
            <div class="mb-3 flex items-center gap-3">
                <h2 class="text-lg font-semibold">Details</h2>
                <StatusBadge :value="record.status ?? record.account_status" :label="record.status_label ?? record.account_status_label" />
            </div>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="k in fields" :key="k" class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-ink-muted">{{ propertyLabel(k, properties[k]) }}</dt>
                    <dd class="mt-0.5 break-words text-sm">{{ displayValue(k, properties[k], record) }}</dd>
                </div>
            </dl>
        </section>

        <ItemImagesPanel v-if="resource.key === 'items'" :item-id="id" :can-edit="resource.can_update" />

        <section v-if="lines.length" class="card">
            <h2 class="mb-3 text-lg font-semibold">Lines</h2>
            <DataTable :columns="columnsFor(lineProperties, lines[0])" :rows="lines" caption="Lines" />
        </section>

        <section v-for="panel in resource.panels" :key="panel.label" class="card">
            <h2 class="mb-3 text-lg font-semibold">{{ panel.label }}</h2>
            <DataTable v-if="panelRows[panel.label]" :columns="columnsFor({}, panelRows[panel.label][0])" :rows="panelRows[panel.label]" :caption="panel.label" empty="Nothing to show." />
            <div v-else class="h-16 animate-pulse rounded-brand bg-surface-muted" aria-busy="true" />
        </section>

        <section v-for="rel in related" :key="rel" class="card">
            <h2 class="mb-3 text-lg font-semibold">{{ propertyLabel(rel) }}</h2>
            <ul v-if="FILE_RELATIONS[resource.key] === rel" class="divide-y divide-line">
                <li v-for="f in record[rel]" :key="f.id" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                    <span class="min-w-0 flex-1 truncate">{{ f.original_filename }}</span>
                    <span class="text-ink-muted">{{ f.mime_type }} · {{ Math.ceil(f.file_size / 1024) }} KB</span>
                    <UiButton icon="download" @click="openFile(f)">Download</UiButton>
                </li>
            </ul>
            <DataTable v-else :columns="columnsFor({}, record[rel][0])" :rows="record[rel]" :caption="rel" />
        </section>
    </div>
    <div v-else-if="!error" class="space-y-3" aria-busy="true">
        <div v-for="n in 3" :key="n" class="h-24 animate-pulse rounded-brand bg-surface" />
    </div>

    <ActionDialog v-if="record" :action="active" :record="record" @close="active = null" @done="done" />
</template>
