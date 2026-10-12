<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import ActionDialog from '@/components/ActionDialog.vue';
import { download, get } from '@/lib/api';
import { loadEnums, loadSchema, propertyLabel, type SchemaProperty } from '@/lib/meta';
import { columnsFor, displayValue, PRINT_TYPES } from '@/lib/display';
import { notify } from '@/lib/toast';
import type { ResourceAction, ResourceMeta } from '@/types/shared';

/** Generic record page: fields, lines, related records and the actions this person may run. */
const props = defineProps<{ resource: ResourceMeta; id: string }>();

const record = ref<Record<string, any> | null>(null);
const properties = ref<Record<string, SchemaProperty>>({});
const lineProperties = ref<Record<string, SchemaProperty>>({});
const error = ref<unknown>(null);
const active = ref<ResourceAction | null>(null);

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
/** Warehouse-floor actions open their scan-first task screen instead of a form dialog. */
const TASKS: Record<string, Record<string, string>> = {
    transfers: { dispatch: 'dispatch', receive: 'receive' },
    stocktakes: { counts: 'count', recounts: 'count' },
    'pick-lists': { picks: 'pick' },
};
const taskHref = (a: ResourceAction) => (TASKS[props.resource.key]?.[a.name] ? `/tasks/${props.resource.key}/${props.id}/${TASKS[props.resource.key][a.name]}` : null);
const visibleActions = computed(() => props.resource.actions.filter((a, i, all) => !taskHref(a) || all.findIndex((b) => taskHref(b) === taskHref(a)) === i));

async function load() {
    try {
        record.value = (await get(`${props.resource.api}/${props.id}`)).data;
    } catch (e) {
        error.value = e;
    }
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
            <UiButton v-if="resource.can_update" icon="edit" :href="`/r/${resource.key}/${id}/edit`">Edit</UiButton>
        </template>
    </PageHeader>
    <ErrorSummary :error="error" />

    <div v-if="record" class="space-y-6">
        <div v-if="visibleActions.length" class="flex flex-wrap gap-2" role="toolbar" aria-label="Actions">
            <template v-for="a in visibleActions" :key="a.uri + a.method">
                <UiButton v-if="taskHref(a)" variant="accent" icon="scan" :href="taskHref(a)!">{{ a.name === 'recounts' ? 'Count' : a.label }}</UiButton>
                <UiButton
                    v-else
                    :variant="/(cancel|reject|archive|blacklist|close|lock|deactivate|suspend|revoke)/.test(a.name) ? 'secondary' : a.idempotent ? 'accent' : 'primary'"
                    @click="active = a"
                >
                    {{ a.label }}
                </UiButton>
            </template>
        </div>

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

        <section v-if="lines.length" class="card">
            <h2 class="mb-3 text-lg font-semibold">Lines</h2>
            <DataTable :columns="columnsFor(lineProperties, lines[0])" :rows="lines" caption="Lines" />
        </section>

        <section v-for="rel in related" :key="rel" class="card">
            <h2 class="mb-3 text-lg font-semibold">{{ propertyLabel(rel) }}</h2>
            <DataTable :columns="columnsFor({}, record[rel][0])" :rows="record[rel]" :caption="rel" />
        </section>
    </div>
    <div v-else-if="!error" class="space-y-3" aria-busy="true">
        <div v-for="n in 3" :key="n" class="h-24 animate-pulse rounded-brand bg-surface" />
    </div>

    <ActionDialog v-if="record" :action="active" :record="record" @close="active = null" @done="done" />
</template>
