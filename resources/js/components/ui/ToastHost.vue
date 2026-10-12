<script setup lang="ts">
import { dismiss, toasts } from '@/lib/toast';
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-20 z-50 flex flex-col items-center gap-2 px-4 md:bottom-6 md:items-end" aria-live="polite">
        <div
            v-for="t in toasts"
            :key="t.id"
            :class="['pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-brand px-4 py-3 text-sm shadow-lg ring-1',
                     t.kind === 'error' ? 'bg-danger text-white ring-danger' : t.kind === 'info' ? 'bg-info text-white ring-info' : 'bg-success text-white ring-success']"
            :role="t.kind === 'error' ? 'alert' : 'status'"
        >
            <p class="flex-1">{{ t.message }}</p>
            <button type="button" class="-m-1 min-h-8 min-w-8 rounded p-1 hover:bg-white/20" aria-label="Dismiss" @click="dismiss(t.id)">✕</button>
        </div>
    </div>
</template>
