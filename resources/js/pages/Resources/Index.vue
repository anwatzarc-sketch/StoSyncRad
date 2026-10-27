<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EnumSelect from '@/components/ui/EnumSelect.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import AreaTabs from '@/components/AreaTabs.vue';
import { get } from '@/lib/api';
import { enumLabel, humanize, loadEnums, loadSchema, propertyLabel, type SchemaProperty } from '@/lib/meta';
import { columnsFor } from '@/lib/display';
import { useShared } from '@/composables/useShared';
import { nextSetupStep } from '@/lib/adminFlow';
import type { NextAction, ResourceMeta } from '@/types/shared';

/**
 * Generic, schema-driven list for any registered resource (search, enum filters, cursor paging).
 * `?filter[field]=a,b` in the URL (links from work queues and alerts) pre-filters the list and shows
 * as removable chips. Next action: start a new record (with the document's workflow spelled out) or
 * go to the hub where its documents wait for people.
 */
const props = defineProps<{ resource: ResourceMeta }>();

const { nav } = useShared();
const properties = ref<Record<string, SchemaProperty>>({});
const rows = ref<Record<string, any>[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);
const params = new URLSearchParams(window.location.search);
const q = ref(params.get('q') ?? '');
const filters = ref<Record<string, string | null>>({});
const urlFilters = ref<Record<string, string>>(Object.fromEntries([...params.entries()]
    .filter(([k, v]) => /^filter\[[a-z_]+\]$/.test(k) && v !== '').map(([k, v]) => [k.slice(7, -1), v])));
let controller: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;

const enumFilters = computed(() => Object.entries(properties.value)
    .filter(([name, p]) => p.filterable && p.type === 'enum' && p.enum && !(name in urlFilters.value)).slice(0, 3));
const columns = computed(() => columnsFor(properties.value, rows.value[0]));
const inStock = computed(() => nav.value.find((s) => s.area === 'stock')?.items.some((i) => i.href === `/r/${props.resource.key}`) ?? false);
const inAdmin = computed(() => nav.value.some((s) => s.area === 'admin' && s.items.some((i) => i.href === `/r/${props.resource.key}`)));
const back = computed(() => (props.resource.hub ? { href: `/work/${props.resource.hub.key}`, label: props.resource.hub.label }
    : inAdmin.value ? { href: '/admin', label: 'Administration' } : inStock.value ? { href: '/stock', label: 'Stock' } : null));
const nextActions = computed<NextAction[]>(() => {
    const wf = props.resource.workflow;
    const path = wf ? wf.steps.map((st) => enumLabel(wf.status_enum, st)).join(' → ') : null;
    const out: NextAction[] = [];
    if (props.resource.can_create) {
        out.push({ label: `New ${props.resource.noun}`, icon: 'plus', href: `/r/${props.resource.key}/new`,
            hint: path ? `A new ${props.resource.noun} goes ${path}.` : `Add a ${props.resource.noun}, or open one below to work on it.` });
    }
    if (props.resource.hub) {
        out.push({ label: `What is waiting in ${props.resource.hub.label}`, icon: 'clipboard', href: `/work/${props.resource.hub.key}`,
            hint: `Documents waiting for your action are listed in ${props.resource.hub.label}.` });
    }
    const setup = nextSetupStep(`/r/${props.resource.key}`, nav.value);

    return setup ? [...out, out.length ? { ...setup, hint: undefined } : setup] : out;
});
const chips = computed(() => Object.entries(urlFilters.value).map(([name, value]) => {
    const p = properties.value[name];

    return { name, text: `${propertyLabel(name, p)}: ${value.split(',').map((v) => (p?.enum ? enumLabel(p.enum, v) : humanize(v))).join(' or ')}` };
}));

function removeFilter(name: string) {
    const next = { ...urlFilters.value };
    delete next[name];
    urlFilters.value = next;
    const url = new URL(window.location.href);
    url.searchParams.delete(`filter[${name}]`);
    window.history.replaceState(window.history.state, '', url);
}

async function load(append = false) {
    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    error.value = null;
    try {
        const r = await get(props.resource.api, {
            q: q.value || undefined,
            filter: { ...urlFilters.value, ...Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v)) },
            cursor: append ? cursor.value : undefined,
            per_page: 25,
        }, controller.signal);
        rows.value = append ? [...rows.value, ...r.data] : r.data;
        cursor.value = r.meta?.next_cursor ?? null;
    } catch (e) {
        if ((e as Error).name !== 'AbortError') {
            error.value = e;
        }
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    if (props.resource.schema) {
        properties.value = (await loadSchema(props.resource.schema).catch(() => ({ properties: {} }))).properties;
    }
    await load();
});

watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => void load(), 300);
});
watch([filters, urlFilters], () => void load(), { deep: true });
</script>

<template>
    <AreaTabs v-if="inStock" area="stock" />
    <PageHeader :title="resource.label" :back="back?.href" :back-label="back?.label" :next="nextActions" />

    <div v-if="chips.length" class="mb-3 flex flex-wrap gap-2" aria-label="Active filters">
        <button
            v-for="c in chips"
            :key="c.name"
            type="button"
            class="inline-flex min-h-9 items-center gap-2 rounded-full border border-brand/30 bg-brand/10 px-3 text-sm text-ink"
            :aria-label="`Remove filter ${c.text}`"
            @click="removeFilter(c.name)"
        >
            {{ c.text }}
            <AppIcon name="x" :size="14" />
        </button>
    </div>

    <div class="card mb-4 grid gap-3 md:grid-cols-4">
        <div class="md:col-span-2">
            <label for="list-search" class="sr-only">Search</label>
            <input id="list-search" v-model="q" type="search" class="input" placeholder="Search…" autocomplete="off">
        </div>
        <div v-for="[name, p] in enumFilters" :key="name">
            <label :for="`filter-${name}`" class="sr-only">{{ propertyLabel(name, p) }}</label>
            <EnumSelect
                :id="`filter-${name}`"
                :enum-code="p.enum!"
                :values="p.enum_values"
                :model-value="filters[name] ?? null"
                nullable
                :placeholder="`${propertyLabel(name, p)}: all`"
                @update:model-value="filters[name] = $event as string | null"
            />
        </div>
    </div>

    <ErrorSummary :error="error" />
    <div class="card">
        <DataTable :columns="columns" :rows="rows" :loading="loading" :row-href="(row) => `/r/${resource.key}/${row.id}`" :caption="resource.label" />
        <div v-if="cursor" class="mt-4 text-center">
            <UiButton :loading="loading" @click="load(true)">Load more</UiButton>
        </div>
    </div>
</template>
