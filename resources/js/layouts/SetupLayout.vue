<script setup lang="ts">
import ToastHost from '@/components/ui/ToastHost.vue';
import { useShared } from '@/composables/useShared';

const { brand } = useShared();
</script>

<template>
    <div class="min-h-dvh bg-surface-muted text-ink">
        <header class="border-b border-line bg-surface">
            <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4">
                <img v-if="brand.assets.logo_mark" :src="brand.assets.logo_mark" alt="" class="h-8 w-8 object-contain">
                <span class="font-bold">{{ brand.app_name }}</span>
                <span class="ml-2 rounded-full bg-accent/10 px-2 py-0.5 text-xs font-semibold text-accent">Platform Setup</span>
            </div>
        </header>
        <main class="mx-auto max-w-6xl px-4 py-6">
            <slot />
        </main>
        <ToastHost />
    </div>
</template>
