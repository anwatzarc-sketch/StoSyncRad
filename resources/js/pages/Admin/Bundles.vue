<script setup lang="ts">
import { ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { download, post } from '@/lib/api';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';

/**
 * Config Bundles (Chapter 03 §3.8): export this deployment's configuration for a new client, or import
 * one — always previewed first (dry run per section), then applied in one transaction.
 */
const { can } = useShared();
const bundle = ref<Record<string, unknown> | null>(null);
const fileName = ref('');
const report = ref<Record<string, any> | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);

async function choose(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    report.value = null;
    error.value = null;
    if (!file) {
        return;
    }
    try {
        bundle.value = JSON.parse(await file.text());
        fileName.value = file.name;
        await run(true);
    } catch (e) {
        bundle.value = null;
        error.value = e instanceof SyntaxError ? new Error('The file is not valid JSON.') : e;
    }
}

async function run(dryRun: boolean) {
    busy.value = true;
    error.value = null;
    try {
        report.value = (await post(`/api/v1/admin/config-bundle${dryRun ? '?dry_run=1' : ''}`, bundle.value)).data;
        if (!dryRun) {
            notify('Bundle applied.');
        }
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHeader title="Config bundles" subtitle="Copy this deployment's configuration to a new client, or apply one here" />
    <ErrorSummary :error="error" />
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card space-y-3">
            <h2 class="text-lg font-semibold">Export</h2>
            <p class="text-sm text-ink-muted">Configuration, lookups, labels, templates, organisation, numbering, roles, shared reports and brand settings. Secrets and record ids are never included.</p>
            <UiButton v-if="can('platform.bundle.export')" icon="download" @click="download('/api/v1/admin/config-bundle', 'bundle.json')">Download bundle.json</UiButton>
        </section>
        <section v-if="can('platform.bundle.import')" class="card space-y-3">
            <h2 class="text-lg font-semibold">Import</h2>
            <label for="bundle-file" class="label">bundle.json</label>
            <input id="bundle-file" type="file" accept="application/json,.json" class="text-sm" @change="choose">
            <div v-if="report" class="space-y-3">
                <p class="text-sm font-semibold">{{ report.dry_run ? `Preview of ${fileName} — nothing changed yet` : 'Applied' }}</p>
                <table class="table">
                    <thead><tr><th scope="col">Section</th><th scope="col">Created</th><th scope="col">Updated</th><th scope="col">Unchanged</th></tr></thead>
                    <tbody>
                        <tr v-for="(c, s) in report.sections" :key="s"><td>{{ s }}</td><td>{{ c.created ?? 0 }}</td><td>{{ c.updated ?? 0 }}</td><td>{{ c.unchanged ?? 0 }}</td></tr>
                    </tbody>
                </table>
                <ul v-if="report.warnings.length" class="list-disc pl-5 text-sm text-warning">
                    <li v-for="(w, i) in report.warnings" :key="i">{{ w.section }}: {{ w.message }}</li>
                </ul>
                <div v-if="Object.keys(report.secrets ?? {}).length" class="rounded-brand bg-warning/10 p-3 text-sm">
                    <p class="font-semibold">New secrets (shown once):</p>
                    <p v-for="(v, k) in report.secrets" :key="k" class="break-all font-mono text-xs">{{ k }}: {{ v }}</p>
                </div>
                <UiButton v-if="report.dry_run" variant="primary" :loading="busy" @click="run(false)">Apply bundle</UiButton>
            </div>
        </section>
    </div>
</template>
