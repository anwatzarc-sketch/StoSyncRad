<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import FormField from '@/components/ui/FormField.vue';
import EnumSelect from '@/components/ui/EnumSelect.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import BarcodeInput from '@/components/BarcodeInput.vue';
import LinesEditor from './LinesEditor.vue';
import { isRequired, propertyLabel, type SchemaProperty } from '@/lib/meta';
import { useShared } from '@/composables/useShared';

/**
 * One input generated from a schema property (Chapter 12 §12.3): type-appropriate control, required
 * marker, max length, enum select with labels, reference picker, writable_by / immutable_after locks.
 */
const props = withDefaults(defineProps<{
    name: string;
    property: SchemaProperty;
    modelValue: unknown;
    operation: 'create' | 'update';
    error?: string | null;
    errors?: Record<string, string>;
    path?: string;
    state?: string | null;
}>(), { error: null, errors: () => ({}), path: '', state: null });

const emit = defineEmits<{ 'update:modelValue': [value: unknown] }>();
const { can } = useShared();

const id = computed(() => `f-${(props.path || props.name).replace(/[^a-zA-Z0-9_-]/g, '-')}`);
const p = computed(() => props.property);
const label = computed(() => propertyLabel(props.name, p.value));

const lockedReason = computed(() => {
    if (p.value.writable_by && !can(p.value.writable_by)) {
        return 'You cannot change this field.';
    }
    if (props.operation === 'update' && props.state && p.value.immutable_after?.includes(props.state)) {
        return 'Locked once the record has been used.';
    }

    return null;
});
const disabled = computed(() => lockedReason.value !== null);
const isDecimal = computed(() => ['decimal', 'money', 'quantity', 'percentage'].includes(p.value.type));
const wide = computed(() => ['text', 'json', 'any', 'array'].includes(p.value.type));

const jsonText = ref('');
const jsonError = ref<string | null>(null);
watch(() => props.modelValue, (v) => {
    if (['json', 'any'].includes(p.value.type)) {
        jsonText.value = v === null || v === undefined ? '' : typeof v === 'string' && p.value.type === 'any' ? JSON.stringify(v) : JSON.stringify(v, null, 2);
    }
}, { immediate: true });

function commitJson() {
    if (jsonText.value.trim() === '') {
        jsonError.value = null;
        emit('update:modelValue', null);

        return;
    }
    try {
        emit('update:modelValue', JSON.parse(jsonText.value));
        jsonError.value = null;
    } catch {
        jsonError.value = 'Not valid JSON.';
    }
}

const scalarList = computed({
    get: () => (Array.isArray(props.modelValue) ? (props.modelValue as unknown[]).join('\n') : ''),
    set: (text: string) => emit('update:modelValue', text.split('\n').map((s) => s.trim()).filter(Boolean)),
});

function onInput(event: Event) {
    const target = event.target as HTMLInputElement;
    let value: unknown = target.value;
    if (p.value.type === 'integer') {
        value = target.value === '' ? null : Number.parseInt(target.value, 10);
    } else if (p.value.type === 'datetime') {
        value = target.value === '' ? null : new Date(target.value).toISOString();
    } else if (value === '' && p.value.nullable !== false) {
        value = null;
    }
    emit('update:modelValue', value);
}

const datetimeLocal = computed(() => {
    if (!props.modelValue) {
        return '';
    }
    const d = new Date(String(props.modelValue));
    if (Number.isNaN(d.getTime())) {
        return '';
    }
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
});

const inputType = computed(() => ({ email: 'email', phone: 'tel', url: 'url', date: 'date', time: 'time' } as Record<string, string>)[p.value.type]
    ?? (p.value.sensitive ? 'password' : 'text'));
</script>

<template>
    <div :class="wide ? 'sm:col-span-2' : ''">
        <LinesEditor
            v-if="p.type === 'array' && p.items"
            :label="label"
            :schema-name="p.items"
            :model-value="(modelValue as Record<string, unknown>[] | null) ?? []"
            :operation="operation"
            :errors="errors"
            :path="path || name"
            :disabled="disabled"
            :required="isRequired(p, operation)"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <FormField v-else :id="id" :label="label" :error="error ?? jsonError" :help="p.help" :required="isRequired(p, operation)" :locked="lockedReason">
            <label v-if="p.type === 'boolean'" class="flex min-h-11 items-center gap-3">
                <input
                    :id="id"
                    type="checkbox"
                    class="size-5 rounded border-line accent-brand"
                    :checked="modelValue === true"
                    :disabled="disabled"
                    @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
                >
                <span class="text-sm text-ink-muted">{{ modelValue === true ? 'Yes' : 'No' }}</span>
            </label>
            <EnumSelect
                v-else-if="p.type === 'enum' && p.enum"
                :id="id"
                :enum-code="p.enum"
                :values="p.enum_values"
                :model-value="(modelValue as string | null)"
                :nullable="!isRequired(p, operation)"
                :disabled="disabled"
                :invalid="!!error"
                @update:model-value="emit('update:modelValue', $event)"
            />
            <EnumSelect
                v-else-if="p.type === 'array' && p.item_enum"
                :id="id"
                :enum-code="p.item_enum"
                :values="p.item_enum_values"
                :model-value="(modelValue as string[] | null) ?? []"
                multiple
                :disabled="disabled"
                :invalid="!!error"
                @update:model-value="emit('update:modelValue', $event)"
            />
            <textarea
                v-else-if="p.type === 'array'"
                :id="id"
                v-model="scalarList"
                class="input min-h-24"
                :disabled="disabled"
                placeholder="One per line"
            />
            <ReferencePicker
                v-else-if="p.type === 'uuid' && p.references"
                :id="id"
                :model="p.references"
                :model-value="(modelValue as string | null)"
                :disabled="disabled"
                :invalid="!!error"
                @update:model-value="emit('update:modelValue', $event)"
            />
            <BarcodeInput
                v-else-if="p.type === 'barcode'"
                :id="id"
                :model-value="(modelValue as string | null) ?? ''"
                :disabled="disabled"
                @update:model-value="emit('update:modelValue', $event || null)"
            />
            <textarea
                v-else-if="p.type === 'text'"
                :id="id"
                class="input min-h-24"
                :value="(modelValue as string | null) ?? ''"
                :maxlength="p.max_length"
                :disabled="disabled"
                :aria-invalid="!!error || undefined"
                @input="onInput"
            />
            <textarea
                v-else-if="p.type === 'json' || p.type === 'any'"
                :id="id"
                v-model="jsonText"
                class="input min-h-28 font-mono text-sm"
                :disabled="disabled"
                spellcheck="false"
                @blur="commitJson"
            />
            <input
                v-else-if="p.type === 'datetime'"
                :id="id"
                type="datetime-local"
                class="input"
                :value="datetimeLocal"
                :disabled="disabled"
                @change="onInput"
            >
            <input
                v-else
                :id="id"
                :type="p.type === 'integer' ? 'number' : inputType"
                :inputmode="isDecimal ? 'decimal' : p.type === 'integer' ? 'numeric' : undefined"
                :step="p.type === 'integer' ? 1 : undefined"
                :class="['input', { 'input-invalid': !!error, 'tabular-nums': isDecimal || p.type === 'integer' }]"
                :value="(modelValue as string | number | null) ?? ''"
                :maxlength="p.max_length"
                :disabled="disabled"
                :autocomplete="p.sensitive ? 'new-password' : 'off'"
                :aria-invalid="!!error || undefined"
                @input="onInput"
            >
        </FormField>
    </div>
</template>
