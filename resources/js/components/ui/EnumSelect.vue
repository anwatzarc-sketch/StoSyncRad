<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { enumCases, loadEnums, humanize } from '@/lib/meta';

/** Enum select with server labels (never raw codes); optionally restricted to `values`. */
const props = withDefaults(defineProps<{
    id: string;
    enumCode: string;
    modelValue: string | string[] | null | undefined;
    values?: string[];
    nullable?: boolean;
    multiple?: boolean;
    disabled?: boolean;
    invalid?: boolean;
    placeholder?: string;
}>(), { values: undefined, nullable: false, multiple: false, disabled: false, invalid: false, placeholder: 'Choose…' });

const emit = defineEmits<{ 'update:modelValue': [value: string | string[] | null] }>();

onMounted(() => void loadEnums().catch(() => undefined));

const options = computed(() => {
    const cases = enumCases(props.enumCode);
    const allowed = props.values ?? cases.map((c) => c.value);

    return allowed.map((value) => ({ value, label: cases.find((c) => c.value === value)?.label ?? humanize(value) }));
});

function onChange(event: Event) {
    const select = event.target as HTMLSelectElement;
    if (props.multiple) {
        emit('update:modelValue', Array.from(select.selectedOptions).map((o) => o.value));
    } else {
        emit('update:modelValue', select.value === '' ? null : select.value);
    }
}

const isSelected = (value: string) => (Array.isArray(props.modelValue) ? props.modelValue.includes(value) : props.modelValue === value);
</script>

<template>
    <select
        :id="id"
        :class="['input', { 'input-invalid': invalid, 'min-h-28': multiple }]"
        :multiple="multiple"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        @change="onChange"
    >
        <option v-if="!multiple" value="" :disabled="!nullable" :selected="modelValue === null || modelValue === undefined || modelValue === ''">
            {{ placeholder }}
        </option>
        <option v-for="o in options" :key="o.value" :value="o.value" :selected="isSelected(o.value)">{{ o.label }}</option>
    </select>
</template>
