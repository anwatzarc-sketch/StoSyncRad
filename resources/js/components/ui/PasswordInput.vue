<script setup lang="ts">
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';
import { generatePassword } from '@/lib/password';
import { notify } from '@/lib/toast';

/**
 * Masked input with a show/hide toggle. With `generate`, it also offers a strong random password:
 * the field is filled and revealed, the password is copied to the clipboard, and `generated` lets
 * the page fill its "repeat password" field. Other attributes (autocomplete, required, class, aria-*)
 * go to the <input>.
 */
defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{ id: string; modelValue: string | null | undefined; generate?: boolean; disabled?: boolean; subject?: string }>(),
    { generate: false, disabled: false, subject: 'password' });
const emit = defineEmits<{ 'update:modelValue': [value: string]; generated: [value: string] }>();

const visible = ref(false);

async function suggest() {
    const password = generatePassword();
    visible.value = true;
    emit('update:modelValue', password);
    emit('generated', password);
    try {
        await navigator.clipboard.writeText(password);
        notify('Strong password generated and copied to the clipboard. Keep it somewhere safe.');
    } catch {
        notify('Strong password generated. Copy it somewhere safe before you continue.', 'info');
    }
}
</script>

<template>
    <div>
        <div class="relative">
            <input
                v-bind="$attrs"
                :id="id"
                :type="visible ? 'text' : 'password'"
                class="input pr-12"
                :value="modelValue ?? ''"
                :disabled="disabled"
                autocapitalize="off"
                spellcheck="false"
                @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            >
            <button
                type="button"
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-brand text-ink-muted hover:text-ink disabled:cursor-not-allowed disabled:opacity-50"
                :aria-label="`${visible ? 'Hide' : 'Show'} ${props.subject}`"
                :title="`${visible ? 'Hide' : 'Show'} ${props.subject}`"
                :aria-controls="id"
                :disabled="disabled"
                @click="visible = !visible"
            >
                <AppIcon :name="visible ? 'eye-off' : 'eye'" :size="18" />
            </button>
        </div>
        <button
            v-if="generate"
            type="button"
            class="mt-1 inline-flex min-h-11 items-center gap-1.5 text-sm text-brand underline disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="disabled"
            @click="suggest"
        >
            <AppIcon name="key" :size="16" />
            Generate a strong password
        </button>
    </div>
</template>
