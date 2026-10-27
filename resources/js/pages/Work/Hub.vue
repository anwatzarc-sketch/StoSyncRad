<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { loadEnums, loadSchema, type SchemaProperty } from '@/lib/meta';
import { columnsFor } from '@/lib/display';
import type { NextAction, WorkHub, WorkQueue, WorkSummary } from '@/types/shared';

/**
 * A workflow hub (WorkflowCatalog::HUBS): how to start new work, and the queues of documents waiting
 * for this person — tabs with counts, the selected queue's documents oldest first. `?queue=` selects
 * a queue (links from Home). Browsing all documents stays one click away. Next action: the oldest
 * document of the selected queue, else another queue with work in it, else the first way to start.
 */
const props = defineProps<{ hub: string }>();

const hub = ref<WorkHub | null>(null);
const error = ref<unknown>(null);
const selected = ref<string | null>(new URLSearchParams(window.location.search).get('queue'));
const rows = ref<Record<string, any>[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(false);
const properties = ref<Record<string, SchemaProperty>>({});

const queue = computed<WorkQueue | null>(() => hub.value?.queues.find((q) => q.key === selected.value) ?? null);
const columns = computed(() => columnsFor(properties.value, rows.value[0]));
const label = (row: Record<string, any>) => String(row.document_number ?? row.movement_number ?? row.code ?? row.name ?? 'the oldest one');
const nextActions = computed<NextAction[]>(() => {
    const q = queue.value;
    if (q && rows.value.length && q.kind !== 'watch') {
        return [{ label: `Open ${label(rows.value[0])}`, icon: 'arrow-right', href: `/r/${q.resource}/${rows.value[0].id}`,
            hint: `${q.count} in “${q.label}”, oldest first.` }];
    }
    const other = hub.value?.queues.find((x) => x.key !== q?.key && x.count > 0 && x.kind !== 'watch');
    if (other) {
        return [{ label: `${other.label} (${other.count})`, icon: other.kind === 'approve' ? 'check' : 'clipboard', onClick: () => select(other.key),
            hint: 'Nothing is waiting in this queue; this one has work.' }];
    }
    const start = hub.value?.start[0];

    return start ? [{ label: start.label, icon: start.icon, href: start.href, hint: 'Nothing is waiting for you here. Start new work.' }] : [];
});

async function loadRows(append = false) {
    if (!queue.value) {
        return;
    }
    loading.value = true;
    try {
        const r = await get(`/api/v1/work/queues/${queue.value.key}`, { cursor: append ? cursor.value : undefined, per_page: 25 });
        rows.value = append ? [...rows.value, ...r.data] : r.data;
        cursor.value = r.meta?.next_cursor ?? null;
    } catch (e) {
        error.value = e;
    } finally {
        loading.value = false;
    }
}

function select(key: string) {
    selected.value = key;
    const url = new URL(window.location.href);
    url.searchParams.set('queue', key);
    window.history.replaceState(window.history.state, '', url);
}

watch(queue, async (q) => {
    rows.value = [];
    cursor.value = null;
    properties.value = q?.schema ? (await loadSchema(q.schema).catch(() => ({ properties: {} }))).properties : {};
    await loadRows();
});

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    try {
        const data = (await get<{ data: WorkSummary }>('/api/v1/work', { hub: props.hub })).data;
        hub.value = data.hubs[0] ?? null;
        const queues = hub.value?.queues ?? [];
        if (!queues.some((q) => q.key === selected.value)) {
            selected.value = (queues.find((q) => q.count > 0 && q.kind !== 'watch') ?? queues[0])?.key ?? null;
        }
    } catch (e) {
        error.value = e;
    }
});
</script>

<template>
    <div>
        <PageHeader :title="hub?.label ?? 'Work'" :subtitle="hub?.description" :next="nextActions" />
        <ErrorSummary :error="error" />

        <section v-if="hub?.start.length" class="mb-6 flex flex-wrap gap-2" aria-label="Start">
            <UiButton v-for="(s, i) in hub.start" :key="s.href" :href="s.href" :icon="s.icon" :variant="i === 0 ? 'primary' : 'secondary'">{{ s.label }}</UiButton>
        </section>

        <div v-if="hub && !hub.queues.length" class="card p-6 text-sm text-ink-muted">Nothing here waits for you. Use the buttons above to start new work.</div>

        <template v-if="hub?.queues.length">
            <div class="-mx-4 mb-4 overflow-x-auto px-4 md:mx-0 md:px-0">
                <div class="flex gap-2" role="tablist" aria-label="Queues">
                    <button
                        v-for="q in hub.queues"
                        :key="q.key"
                        type="button"
                        role="tab"
                        :aria-selected="q.key === selected"
                        :class="['flex min-h-11 shrink-0 items-center gap-2 rounded-full border px-4 text-sm whitespace-nowrap transition',
                                 q.key === selected ? 'border-brand bg-brand text-brand-contrast' : 'border-line bg-surface hover:bg-surface-muted']"
                        @click="select(q.key)"
                    >
                        <AppIcon v-if="q.kind === 'approve'" name="check" :size="15" />
                        {{ q.label }}
                        <span
                            :class="['min-w-6 rounded-full px-1.5 text-center text-xs font-bold tabular-nums',
                                     q.key === selected ? 'bg-white/25' : q.count > 0 && q.kind !== 'watch' ? 'bg-accent text-accent-contrast' : 'bg-surface-muted text-ink-muted']"
                        >{{ q.count }}</span>
                    </button>
                </div>
            </div>

            <div class="card">
                <DataTable
                    :columns="columns"
                    :rows="rows"
                    :loading="loading"
                    :row-href="(row) => `/r/${queue?.resource}/${row.id}`"
                    :caption="queue?.label ?? ''"
                    empty="Nothing is waiting here."
                />
                <div v-if="cursor" class="mt-4 text-center">
                    <UiButton :loading="loading" @click="loadRows(true)">Load more</UiButton>
                </div>
            </div>
        </template>

        <nav v-if="hub?.lists.length" class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm" aria-label="All documents">
            <span class="text-ink-muted">Browse all:</span>
            <Link v-for="l in hub.lists" :key="l.href" :href="l.href" class="font-semibold text-brand hover:underline">{{ l.label }}</Link>
        </nav>
    </div>
</template>
