<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { nextSetupStep } from '@/lib/adminFlow';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, put } from '@/lib/api';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';
import type { NextAction } from '@/types/shared';

/** Admin-editable lists (reasons, payment terms, departments…) — values carry no code semantics. */
const { can, nav } = useShared();
/** Next action: the next going-live step (lib/adminFlow). */
const nextActions = computed<NextAction[]>(() => [nextSetupStep('/admin/lookups', nav.value)]
    .filter((a): a is NextAction => !!a));
const lookups = ref<Record<string, any>[]>([]);
const selected = ref<string>('');
const draft = reactive({ code: '', label: '', sort_order: 0, is_active: true });
const error = ref<unknown>(null);

const lookup = computed(() => lookups.value.find((l) => l.code === selected.value) ?? null);

async function load() {
    lookups.value = (await get('/api/v1/admin/lookups')).data;
    selected.value ||= lookups.value[0]?.code ?? '';
}

async function save(value: { code: string; label: string; sort_order: number; is_active: boolean }) {
    error.value = null;
    try {
        await put(`/api/v1/admin/lookups/${selected.value}/values`, value);
        notify('Saved.');
        Object.assign(draft, { code: '', label: '', sort_order: 0, is_active: true });
        await load();
    } catch (e) {
        error.value = e;
    }
}

onMounted(() => load().catch((e) => (error.value = e)));
</script>

<template>
    <PageHeader title="Lookups" :next="nextActions" />
    <ErrorSummary :error="error" />
    <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
        <nav class="card p-2" aria-label="Lookups">
            <button v-for="l in lookups" :key="l.code" type="button"
                    :class="['block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-surface-muted', selected === l.code ? 'bg-surface-muted font-semibold' : '']"
                    @click="selected = l.code">
                {{ l.label }}
            </button>
        </nav>
        <section v-if="lookup" class="card">
            <h2 class="mb-3 text-lg font-semibold">{{ lookup.label }}</h2>
            <table class="table">
                <thead><tr><th scope="col">Code</th><th scope="col">Label</th><th scope="col">Order</th><th scope="col">Active</th><th /></tr></thead>
                <tbody>
                    <tr v-for="v in lookup.values" :key="v.id">
                        <td class="font-mono text-xs">{{ v.code }}</td>
                        <td><input v-model="v.label" class="input min-h-9" :disabled="!can('platform.lookup.manage')" :aria-label="`Label of ${v.code}`"></td>
                        <td><input v-model.number="v.sort_order" type="number" class="input min-h-9 w-20" :disabled="!can('platform.lookup.manage')" :aria-label="`Order of ${v.code}`"></td>
                        <td><input v-model="v.is_active" type="checkbox" class="size-5 accent-brand" :disabled="!can('platform.lookup.manage')" :aria-label="`${v.code} active`"></td>
                        <td>
                            <UiButton v-if="can('platform.lookup.manage')" @click="save({ code: v.code, label: v.label, sort_order: v.sort_order, is_active: v.is_active })">Save</UiButton>
                        </td>
                    </tr>
                </tbody>
            </table>
            <form v-if="can('platform.lookup.manage')" class="mt-4 grid gap-2 border-t border-line pt-4 md:grid-cols-[10rem_1fr_6rem_auto] md:items-end" @submit.prevent="save({ ...draft })">
                <div>
                    <label for="lv-code" class="label">New code</label>
                    <input id="lv-code" v-model="draft.code" class="input font-mono" pattern="[a-z0-9_]+" required>
                </div>
                <div>
                    <label for="lv-label" class="label">Label</label>
                    <input id="lv-label" v-model="draft.label" class="input" required>
                </div>
                <div>
                    <label for="lv-order" class="label">Order</label>
                    <input id="lv-order" v-model.number="draft.sort_order" type="number" class="input">
                </div>
                <UiButton type="submit" variant="primary">Add</UiButton>
            </form>
        </section>
    </div>
</template>
