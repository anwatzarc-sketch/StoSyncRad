<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';

/** Accessible modal on the native <dialog> element (focus trap, Esc, backdrop). */
const props = withDefaults(defineProps<{ open: boolean; title: string; wide?: boolean }>(), { wide: false });
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement | null>(null);

function sync() {
    if (!dialog.value) {
        return;
    }
    if (props.open && !dialog.value.open) {
        dialog.value.showModal();
    } else if (!props.open && dialog.value.open) {
        dialog.value.close();
    }
}

watch(() => props.open, sync);
onMounted(sync);
</script>

<template>
    <dialog
        ref="dialog"
        :class="['w-[calc(100%-2rem)] rounded-brand border border-line bg-surface p-0 text-ink shadow-xl backdrop:bg-black/40', wide ? 'max-w-3xl' : 'max-w-lg']"
        :aria-label="title"
        @cancel.prevent="emit('close')"
    >
        <div class="flex items-center justify-between border-b border-line px-5 py-3">
            <h2 class="text-lg font-semibold">{{ title }}</h2>
            <button type="button" class="btn btn-ghost min-h-9 px-2" aria-label="Close" @click="emit('close')">✕</button>
        </div>
        <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
            <slot />
        </div>
        <div v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3">
            <slot name="footer" />
        </div>
    </dialog>
</template>
