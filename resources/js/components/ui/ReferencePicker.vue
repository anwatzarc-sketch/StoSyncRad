<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { get } from '@/lib/api';
import { REFERENCE_RESOURCES } from '@/types/generated';

/**
 * Searchable picker for a referenced record (schema `references`), backed by the resource's list
 * API — so the person only sees records they may read, in their scope.
 */
const props = withDefaults(defineProps<{
    id: string;
    model?: string;
    resource?: string;
    modelValue: string | null | undefined;
    disabled?: boolean;
    invalid?: boolean;
    filters?: Record<string, unknown>;
}>(), { model: undefined, resource: undefined, disabled: false, invalid: false, filters: undefined });

const emit = defineEmits<{ 'update:modelValue': [value: string | null]; picked: [row: Record<string, any> | null] }>();

type Row = Record<string, any>;

const key = props.resource ?? (props.model ? REFERENCE_RESOURCES[props.model] : undefined);
const query = ref('');
const results = ref<Record<string, any>[]>([]);
const open = ref(false);
const loading = ref(false);
const selectedLabel = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | null = null;

function labelOf(row: Row): string {
    const main = row.document_number ?? row.sku ?? row.code ?? row.username ?? row.lot_number ?? row.serial_number ?? row.movement_number;
    const name = row.name ?? row.full_name ?? row.display_name ?? row.legal_name ?? row.label;

    return [main, name].filter(Boolean).join(' — ') || row.id;
}

async function search(text: string) {
    if (!key) {
        return;
    }
    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    try {
        const r = await get<{ data: Row[] }>(`/api/v1/${key}`, { q: text || undefined, per_page: 20, filter: props.filters }, controller.signal);
        results.value = r.data;
    } catch (e) {
        if ((e as Error).name !== 'AbortError') {
            results.value = [];
        }
    } finally {
        loading.value = false;
    }
}

watch(query, (text) => {
    clearTimeout(timer);
    timer = setTimeout(() => void search(text), 250);
});

watch(() => props.modelValue, async (id) => {
    if (!id || !key) {
        selectedLabel.value = '';

        return;
    }
    if (results.value.find((r) => r.id === id)) {
        selectedLabel.value = labelOf(results.value.find((r) => r.id === id)!);

        return;
    }
    try {
        selectedLabel.value = labelOf((await get<{ data: Row }>(`/api/v1/${key}/${id}`)).data);
    } catch {
        selectedLabel.value = id;
    }
}, { immediate: true });

function choose(row: Row | null) {
    emit('update:modelValue', row?.id ?? null);
    emit('picked', row);
    selectedLabel.value = row ? labelOf(row) : '';
    open.value = false;
    query.value = '';
}

function onBlur() {
    setTimeout(() => (open.value = false), 200);
}

function focus() {
    open.value = true;
    if (results.value.length === 0) {
        void search('');
    }
}

onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <div class="relative">
        <div v-if="!open" class="flex gap-2">
            <button
                :id="id"
                type="button"
                :class="['input text-left', { 'input-invalid': invalid, 'text-ink-muted': !selectedLabel }]"
                :disabled="disabled || !key"
                @click="focus"
            >
                {{ selectedLabel || (key ? 'Search…' : 'Not available') }}
            </button>
            <button v-if="modelValue && !disabled" type="button" class="btn btn-ghost px-3" aria-label="Clear" @click="choose(null)">✕</button>
        </div>
        <div v-else>
            <input
                :id="id"
                v-model="query"
                type="search"
                class="input"
                autocomplete="off"
                placeholder="Type to search…"
                @keydown.esc="open = false"
                @blur="onBlur"
            >
            <ul class="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-brand border border-line bg-surface shadow-lg" role="listbox">
                <li v-if="loading" class="px-3 py-2 text-sm text-ink-muted">Searching…</li>
                <li v-else-if="results.length === 0" class="px-3 py-2 text-sm text-ink-muted">No matches.</li>
                <li v-for="row in results" :key="row.id">
                    <button type="button" class="block min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-surface-muted" @mousedown.prevent="choose(row)">
                        {{ labelOf(row) }}
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
