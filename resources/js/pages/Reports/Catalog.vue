<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { enumLabel, loadEnums } from '@/lib/meta';
import { useShared } from '@/composables/useShared';

/** Saved reports visible to this person (own, shared, standard) and the datasets they may use. */
const { can } = useShared();
const definitions = ref<Record<string, any>[]>([]);
const datasets = ref<Record<string, any>[]>([]);
const q = ref('');
const scope = ref<'' | 'mine' | 'system'>('');
const error = ref<unknown>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

const datasetLabel = computed(() => Object.fromEntries(datasets.value.map((d) => [d.code, d.label])));
const grouped = computed(() => {
    const out: Record<string, Record<string, any>[]> = {};
    for (const d of definitions.value) {
        (out[d.is_system_seed ? 'Standard reports' : d.owner_account_id ? 'My and shared reports' : 'Shared reports'] ??= []).push(d);
    }

    return out;
});

async function load() {
    try {
        definitions.value = (await get('/api/v1/report-definitions', { q: q.value || undefined, scope: scope.value || undefined })).data;
    } catch (e) {
        error.value = e;
    }
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    datasets.value = (await get('/api/v1/reporting/catalog').catch(() => ({ data: [] }))).data;
    await load();
});
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => void load(), 300);
});
watch(scope, () => void load());
</script>

<template>
    <PageHeader title="Reports">
        <template #actions>
            <UiButton icon="history" href="/reports/runs">Runs & exports</UiButton>
            <UiButton v-if="can('reporting.definition.create')" variant="primary" icon="plus" href="/reports/new">New report</UiButton>
        </template>
    </PageHeader>
    <ErrorSummary :error="error" />
    <div class="card mb-4 grid gap-3 md:grid-cols-[1fr_14rem]">
        <input v-model="q" type="search" class="input" placeholder="Search reports…" aria-label="Search reports">
        <select v-model="scope" class="input" aria-label="Show">
            <option value="">All I can see</option>
            <option value="mine">Mine</option>
            <option value="system">Standard</option>
        </select>
    </div>
    <section v-for="(items, title) in grouped" :key="title" class="mb-6">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ title }}</h2>
        <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <li v-for="d in items" :key="d.id">
                <Link :href="`/reports/${d.id}`" class="card block h-full hover:border-brand">
                    <p class="font-semibold">{{ d.name }}</p>
                    <p class="mt-1 line-clamp-2 text-sm text-ink-muted">{{ d.description || datasetLabel[d.dataset_code] || d.dataset_code }}</p>
                    <p class="mt-2 text-xs text-ink-muted">{{ datasetLabel[d.dataset_code] ?? d.dataset_code }} · {{ enumLabel('report_visibility', d.visibility) }}</p>
                </Link>
            </li>
        </ul>
    </section>
    <p v-if="definitions.length === 0" class="card text-center text-sm text-ink-muted">No reports match.</p>
</template>
