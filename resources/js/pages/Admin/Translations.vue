<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { ApiError, get, put } from '@/lib/api';
import { nextSetupStep } from '@/lib/adminFlow';
import { enumCases, humanize, loadEnums } from '@/lib/meta';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';
import type { NextAction } from '@/types/shared';

/**
 * Your own wording (Chapter 03 §3.6): text overrides per locale (`key` = the dotted text key), and
 * the labels shown for fixed values such as statuses and condition codes. Codes never change — only
 * what people read. Every change is audited.
 */
interface Override { locale: string; key: string; value: string; updated_at?: string }

const { can, nav, props } = useShared();
const locales = computed(() => props.value.locale.enabled ?? [props.value.locale.current]);
const locale = ref(props.value.locale.current);
const overrides = ref<Override[]>([]);
const q = ref('');
const draft = reactive({ key: '', value: '' });
const label = reactive({ enum_code: '', case_value: '', label: '', description: '' });
const enumCodes = ref<string[]>([]);
const busy = ref<'text' | 'label' | null>(null);
const error = ref<unknown>(null);
const fieldErrors = computed(() => (error.value instanceof ApiError ? error.value.fieldErrors() : {}));

const shown = computed(() => overrides.value.filter((o) => !q.value || `${o.key} ${o.value}`.toLowerCase().includes(q.value.toLowerCase())));
const cases = computed(() => (label.enum_code ? enumCases(label.enum_code) : []));
const nextActions = computed<NextAction[]>(() => [
    can('platform.translation.manage') ? { label: 'Add a text override', icon: 'plus', onClick: () => document.getElementById('tr-key')?.focus(), hint: 'Change any wording without touching the code — per language.' } : null,
    nextSetupStep('/admin/translations', nav.value),
].filter((a): a is NextAction => !!a));

async function load() {
    try {
        overrides.value = (await get<{ data: Override[] }>('/api/v1/admin/translations', { locale: locale.value })).data;
    } catch (e) {
        error.value = e;
    }
}

function edit(o: Override) {
    Object.assign(draft, { key: o.key, value: o.value });
    document.getElementById('tr-value')?.focus();
}

async function saveText() {
    busy.value = 'text';
    error.value = null;
    try {
        await put('/api/v1/admin/translations', { locale: locale.value, key: draft.key.trim(), value: draft.value });
        notify('Text saved.');
        Object.assign(draft, { key: '', value: '' });
        await load();
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = null;
    }
}

async function saveLabel() {
    busy.value = 'label';
    error.value = null;
    try {
        await put('/api/v1/admin/enum-labels', { enum_code: label.enum_code, case_value: label.case_value, locale: locale.value, label: label.label.trim(),
            description: label.description.trim() || null });
        notify('Label saved. It shows everywhere the value appears.');
        Object.assign(label, { case_value: '', label: '', description: '' });
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = null;
    }
}

watch(() => label.case_value, (value) => {
    const current = cases.value.find((c) => c.value === value);
    label.label = current?.label ?? '';
    label.description = current?.description ?? '';
});
watch(locale, () => void load());

onMounted(async () => {
    const all = await loadEnums().catch(() => ({}));
    enumCodes.value = Object.keys(all).sort();
    await load();
});
</script>

<template>
    <PageHeader title="Translations & labels" subtitle="Your own wording for texts and for fixed values such as statuses" :next="nextActions">
        <template #actions>
            <label class="flex items-center gap-2 text-sm">
                Language
                <select v-model="locale" class="input w-auto" aria-label="Language">
                    <option v-for="l in locales" :key="l" :value="l">{{ l }}</option>
                </select>
            </label>
        </template>
    </PageHeader>
    <ErrorSummary :error="error" />

    <section v-if="can('platform.translation.manage')" class="card mb-6" aria-labelledby="texts-title">
        <h2 id="texts-title" class="mb-3 text-lg font-semibold">Text overrides</h2>
        <form class="mb-4 grid gap-3 md:grid-cols-[18rem_1fr_auto] md:items-end" @submit.prevent="saveText">
            <div>
                <label for="tr-key" class="label">Text key</label>
                <input id="tr-key" v-model="draft.key" class="input font-mono" placeholder="e.g. receiving.finish" pattern="[a-z0-9_.]{1,190}" required>
                <p v-if="fieldErrors.key" class="error-text">{{ fieldErrors.key }}</p>
            </div>
            <div>
                <label for="tr-value" class="label">Text</label>
                <input id="tr-value" v-model="draft.value" class="input" maxlength="2000" required>
                <p v-if="fieldErrors.value" class="error-text">{{ fieldErrors.value }}</p>
            </div>
            <UiButton type="submit" variant="primary" :loading="busy === 'text'" :disabled="!draft.key || !draft.value">Save</UiButton>
        </form>
        <input v-model="q" type="search" class="input mb-3" placeholder="Search overrides…" aria-label="Search overrides">
        <p v-if="!shown.length" class="text-sm text-ink-muted">No overrides for {{ locale }} yet — the standard wording is used.</p>
        <ul class="divide-y divide-line text-sm">
            <li v-for="o in shown" :key="o.key" class="flex flex-wrap items-center gap-3 py-2">
                <code class="min-w-48 font-mono text-xs">{{ o.key }}</code>
                <span class="min-w-0 flex-1">{{ o.value }}</span>
                <UiButton variant="ghost" icon="edit" @click="edit(o)">Edit</UiButton>
            </li>
        </ul>
    </section>

    <section v-if="can('platform.enum_label.manage')" class="card" aria-labelledby="labels-title">
        <h2 id="labels-title" class="text-lg font-semibold">Labels of fixed values</h2>
        <p class="mb-3 text-sm text-ink-muted">Statuses, conditions and other fixed values keep their codes; here you choose what people read for them.</p>
        <form class="grid gap-3 md:grid-cols-2" @submit.prevent="saveLabel">
            <div>
                <label for="el-enum" class="label">List</label>
                <select id="el-enum" v-model="label.enum_code" class="input" required @change="label.case_value = ''">
                    <option value="" disabled>Choose…</option>
                    <option v-for="code in enumCodes" :key="code" :value="code">{{ humanize(code) }}</option>
                </select>
            </div>
            <div>
                <label for="el-case" class="label">Value</label>
                <select id="el-case" v-model="label.case_value" class="input" :disabled="!label.enum_code" required>
                    <option value="" disabled>Choose…</option>
                    <option v-for="c in cases" :key="c.value" :value="c.value">{{ c.value }} — {{ c.label }}</option>
                </select>
            </div>
            <div>
                <label for="el-label" class="label">Label</label>
                <input id="el-label" v-model="label.label" class="input" maxlength="160" required>
                <p v-if="fieldErrors.label" class="error-text">{{ fieldErrors.label }}</p>
            </div>
            <div>
                <label for="el-description" class="label">Description (optional)</label>
                <input id="el-description" v-model="label.description" class="input" maxlength="1000">
            </div>
            <div class="md:col-span-2 flex justify-end">
                <UiButton type="submit" variant="primary" :loading="busy === 'label'" :disabled="!label.case_value || !label.label.trim()">Save label</UiButton>
            </div>
        </form>
    </section>
</template>
