<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, post } from '@/lib/api';
import { enumLabel, loadEnums } from '@/lib/meta';
import { formatDateTime } from '@/lib/format';
import { useShared } from '@/composables/useShared';

/** Report runs and exports: status, row counts, signed one-time download links, cancel. */
const { can } = useShared();
const runs = ref<Record<string, any>[]>([]);
const all = ref(false);
const error = ref<unknown>(null);
let timer: ReturnType<typeof setInterval> | undefined;

async function load() {
    try {
        runs.value = (await get(all.value ? '/api/v1/report-runs/all' : '/api/v1/report-runs')).data;
    } catch (e) {
        error.value = e;
    }
}

async function openRun(id: string) {
    const run = (await get(`/api/v1/report-runs/${id}`)).data;
    if (run.download_url) {
        window.location.assign(run.download_url);
    } else {
        error.value = new Error(run.file_expires_at ? 'This export has expired.' : 'There is no file for this run.');
    }
}

async function cancel(id: string) {
    await post(`/api/v1/report-runs/${id}/cancel`);
    await load();
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    await load();
    timer = setInterval(() => {
        if (runs.value.some((r) => ['queued', 'running'].includes(r.status))) {
            void load();
        }
    }, 4000);
});
onBeforeUnmount(() => clearInterval(timer));
watch(all, () => void load());
</script>

<template>
    <PageHeader title="Report runs" back="/reports">
        <template #actions>
            <label v-if="can('reporting.run.read_all')" class="flex min-h-11 items-center gap-2 text-sm">
                <input v-model="all" type="checkbox" class="size-5 accent-brand"> Everyone's runs
            </label>
        </template>
    </PageHeader>
    <ErrorSummary :error="error" />
    <div class="card">
        <p v-if="runs.length === 0" class="py-8 text-center text-sm text-ink-muted">No runs yet.</p>
        <ul class="divide-y divide-line">
            <li v-for="r in runs" :key="r.id" class="flex flex-wrap items-center gap-3 py-3">
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ r.dataset_code }} · {{ enumLabel('report_output_format', r.output_format) }}</p>
                    <p class="text-sm text-ink-muted">
                        {{ formatDateTime(r.created_at) }}<template v-if="r.row_count !== null"> · {{ r.row_count }} rows</template>
                        <template v-if="r.truncated"> · truncated</template>
                        <template v-if="r.error_message"> · {{ r.error_message }}</template>
                    </p>
                </div>
                <StatusBadge :value="r.status" :label="r.status_label" />
                <UiButton v-if="r.status === 'completed' && r.output_format !== 'web'" icon="download" @click="openRun(r.id)">Download</UiButton>
                <UiButton v-if="['queued', 'running'].includes(r.status)" @click="cancel(r.id)">Cancel</UiButton>
            </li>
        </ul>
    </div>
</template>
