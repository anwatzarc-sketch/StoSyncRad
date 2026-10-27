<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { nextSetupStep } from '@/lib/adminFlow';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, post, put } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';
import type { NextAction } from '@/types/shared';

/**
 * Document, label, e-mail and report templates (Chapter 03 §3.6). Bodies use the sandboxed template
 * language ({{ path | filter }}, {{#each}}, {{#if}}); the preview renders in a sandboxed frame.
 */
const { can, nav } = useShared();
/** Next action: the next going-live step (lib/adminFlow). */
const nextActions = computed<NextAction[]>(() => [nextSetupStep('/admin/templates', nav.value)]
    .filter((a): a is NextAction => !!a));
const templates = ref<Record<string, any>[]>([]);
const current = ref<Record<string, any> | null>(null);
const body = ref('');
const sample = ref('{}');
const previewHtml = ref('');
const busy = ref(false);
const error = ref<unknown>(null);

async function open(id: string) {
    error.value = null;
    current.value = (await get(`/api/v1/admin/templates/${id}`)).data;
    body.value = current.value!.body;
    previewHtml.value = '';
}

async function preview() {
    error.value = null;
    try {
        const r = await post('/api/v1/admin/templates/preview', { body: body.value, sample: JSON.parse(sample.value || '{}') });
        previewHtml.value = r.data.html ?? r.data.rendered ?? String(r.data);
    } catch (e) {
        error.value = e;
    }
}

async function save() {
    busy.value = true;
    error.value = null;
    try {
        await put(`/api/v1/admin/templates/${current.value!.id}`, { body: body.value, is_active: true });
        notify('Template saved; a new version was recorded.');
        await open(current.value!.id);
        templates.value = (await get('/api/v1/admin/templates')).data;
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

onMounted(async () => {
    try {
        templates.value = (await get('/api/v1/admin/templates')).data;
    } catch (e) {
        error.value = e;
    }
});
</script>

<template>
    <PageHeader title="Templates" :next="nextActions" />
    <ErrorSummary :error="error" />
    <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
        <nav class="card p-2" aria-label="Templates">
            <ul>
                <li v-for="t in templates" :key="t.id">
                    <button type="button" :class="['flex min-h-11 w-full flex-col items-start rounded px-3 py-2 text-left text-sm hover:bg-surface-muted', current?.id === t.id ? 'bg-surface-muted font-semibold' : '']"
                            @click="open(t.id)">
                        {{ t.name }}
                        <span class="font-mono text-xs text-ink-muted">{{ t.code }} · v{{ t.version }}{{ t.is_system_default ? ' · default' : '' }}</span>
                    </button>
                </li>
            </ul>
        </nav>
        <section v-if="current" class="card space-y-4">
            <div>
                <h2 class="text-lg font-semibold">{{ current.name }}</h2>
                <p class="text-sm text-ink-muted">Updated {{ formatDateTime(current.updated_at) }} · {{ current.versions?.length ?? 0 }} earlier versions</p>
            </div>
            <div>
                <label for="tpl-body" class="label">Body</label>
                <textarea id="tpl-body" v-model="body" class="input min-h-80 font-mono text-sm" spellcheck="false" :disabled="!can('platform.template.manage')" />
            </div>
            <details>
                <summary class="cursor-pointer text-sm font-medium">Sample data for preview (JSON)</summary>
                <textarea v-model="sample" class="input mt-2 min-h-32 font-mono text-sm" spellcheck="false" aria-label="Sample data" />
            </details>
            <div class="flex flex-wrap gap-2">
                <UiButton @click="preview">Preview</UiButton>
                <UiButton v-if="can('platform.template.manage')" variant="primary" :loading="busy" @click="save">Save</UiButton>
            </div>
            <iframe v-if="previewHtml" title="Preview" sandbox="" :srcdoc="previewHtml" class="h-[32rem] w-full rounded-brand border border-line bg-white" />
        </section>
        <p v-else class="card text-sm text-ink-muted">Choose a template to edit.</p>
    </div>
</template>
