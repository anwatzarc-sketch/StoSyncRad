<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EnumSelect from '@/components/ui/EnumSelect.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { loadEnums, loadSchema, propertyLabel, type SchemaProperty } from '@/lib/meta';
import { columnsFor } from '@/lib/display';
import type { ResourceMeta } from '@/types/shared';

/** Generic, schema-driven list for any registered resource (search, enum filters, cursor paging). */
const props = defineProps<{ resource: ResourceMeta }>();

const properties = ref<Record<string, SchemaProperty>>({});
const rows = ref<Record<string, any>[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);
const q = ref(new URLSearchParams(window.location.search).get('q') ?? '');
const filters = ref<Record<string, string | null>>({});
let controller: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;

const enumFilters = computed(() => Object.entries(properties.value).filter(([, p]) => p.filterable && p.type === 'enum' && p.enum).slice(0, 3));
const columns = computed(() => columnsFor(properties.value, rows.value[0]));

async function load(append = false) {
    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    error.value = null;
    try {
        const r = await get(props.resource.api, {
            q: q.value || undefined,
            filter: Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v)),
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
watch(filters, () => void load(), { deep: true });
</script>

<template>
    <PageHeader :title="resource.label">
        <template #actions>
            <UiButton v-if="resource.can_create" variant="primary" icon="plus" :href="`/r/${resource.key}/new`">New</UiButton>
        </template>
    </PageHeader>

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
