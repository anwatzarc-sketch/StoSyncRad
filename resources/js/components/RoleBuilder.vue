<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { get } from '@/lib/api';

/**
 * Role builder (Chapter 04 §4.9, Chapter 12 §12.5): a role is just the permissions an administrator
 * picks. Grouped by module and resource, searchable, with select-all; permissions implied by a
 * picked one are shown checked and locked; elevated/critical ones are marked.
 */
interface Permission { code: string; module: string; resource: string; action: string; label: string; description: string; sensitivity: string; implies: string[] }

const props = withDefaults(defineProps<{ modelValue: string[]; source?: string; disabled?: boolean }>(), { source: '/api/v1/meta/permissions', disabled: false });
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

const modules = ref<Array<{ code: string; label: string }>>([]);
const permissions = ref<Permission[]>([]);
const search = ref('');
const open = ref<Record<string, boolean>>({});
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const r = (await get(props.source)).data;
        modules.value = r.modules;
        permissions.value = r.permissions.filter((p: Permission & { is_virtual?: boolean }) => !p.is_virtual);
    } catch (e) {
        error.value = (e as Error).message;
    }
});

const byCode = computed(() => new Map(permissions.value.map((p) => [p.code, p])));
const selected = computed(() => new Set(props.modelValue));
const implied = computed(() => {
    const out = new Set<string>();
    const queue = [...props.modelValue];
    while (queue.length) {
        for (const i of byCode.value.get(queue.pop()!)?.implies ?? []) {
            if (!out.has(i)) {
                out.add(i);
                queue.push(i);
            }
        }
    }

    return out;
});
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();

    return q ? permissions.value.filter((p) => `${p.code} ${p.label} ${p.description}`.toLowerCase().includes(q)) : permissions.value;
});
const tree = computed(() => modules.value.map((m) => {
    const items = filtered.value.filter((p) => p.module === m.code);
    const resources = Array.from(new Set(items.map((p) => p.resource)));

    return { ...m, count: items.length, picked: items.filter((p) => selected.value.has(p.code)).length,
        resources: resources.map((r) => ({ code: r, items: items.filter((p) => p.resource === r) })) };
}).filter((m) => m.count > 0));
const critical = computed(() => props.modelValue.filter((c) => byCode.value.get(c)?.sensitivity === 'critical'));

function toggle(code: string) {
    const next = new Set(props.modelValue);
    next.has(code) ? next.delete(code) : next.add(code);
    emit('update:modelValue', Array.from(next).sort());
}

function setAll(codes: string[], on: boolean) {
    const next = new Set(props.modelValue);
    codes.forEach((c) => (on ? next.add(c) : next.delete(c)));
    emit('update:modelValue', Array.from(next).sort());
}
</script>

<template>
    <div class="space-y-3">
        <p v-if="error" class="error-text">{{ error }}</p>
        <div class="flex flex-wrap items-center gap-3">
            <input v-model="search" type="search" class="input max-w-sm" placeholder="Search permissions…" aria-label="Search permissions">
            <span class="text-sm text-ink-muted">{{ modelValue.length }} chosen · {{ implied.size }} included automatically</span>
        </div>
        <p v-if="critical.length" class="rounded-brand bg-warning/10 p-3 text-sm">
            Critical permissions (always need recent re-verification): {{ critical.join(', ') }}
        </p>
        <ul class="space-y-2">
            <li v-for="m in tree" :key="m.code" class="rounded-brand border border-line">
                <div class="flex items-center gap-3 px-3">
                    <button type="button" class="flex min-h-11 flex-1 items-center gap-2 text-left font-semibold" :aria-expanded="!!open[m.code] || !!search"
                            @click="open[m.code] = !open[m.code]">
                        {{ open[m.code] || search ? '▾' : '▸' }} {{ m.label }}
                        <span class="text-sm font-normal text-ink-muted">{{ m.picked }}/{{ m.count }}</span>
                    </button>
                    <button v-if="!disabled" type="button" class="text-sm text-brand underline"
                            @click="setAll(m.resources.flatMap((r) => r.items.map((p) => p.code)), m.picked < m.count)">
                        {{ m.picked < m.count ? 'Select all' : 'Clear' }}
                    </button>
                </div>
                <div v-if="open[m.code] || search" class="space-y-3 border-t border-line p-3">
                    <fieldset v-for="r in m.resources" :key="r.code">
                        <legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ r.code.replace(/_/g, ' ') }}</legend>
                        <div class="grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
                            <label v-for="p in r.items" :key="p.code" class="flex min-h-9 items-start gap-2 text-sm" :title="p.description || p.code">
                                <input type="checkbox" class="mt-0.5 size-4 accent-brand" :checked="selected.has(p.code) || implied.has(p.code)"
                                       :disabled="disabled || (implied.has(p.code) && !selected.has(p.code))" @change="toggle(p.code)">
                                <span>
                                    {{ p.label }}
                                    <span v-if="p.sensitivity !== 'normal'" :class="['ml-1 rounded px-1 text-[11px] font-semibold', p.sensitivity === 'critical' ? 'bg-danger/10 text-danger' : 'bg-warning/10 text-warning']">
                                        {{ p.sensitivity }}
                                    </span>
                                    <span v-if="implied.has(p.code) && !selected.has(p.code)" class="ml-1 text-xs text-ink-muted">(included)</span>
                                </span>
                            </label>
                        </div>
                    </fieldset>
                </div>
            </li>
        </ul>
    </div>
</template>
