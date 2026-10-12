<script setup lang="ts">
import ProductFooter from '@/components/ProductFooter.vue';
import ProductWordmark from '@/components/ProductWordmark.vue';
import ToastHost from '@/components/ui/ToastHost.vue';
</script>

<template>
    <div class="min-h-dvh bg-surface-muted text-ink">
        <header class="border-b border-line bg-surface">
            <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4">
                <ProductWordmark show-company />
                <span class="ml-2 rounded-full bg-accent/10 px-2 py-0.5 text-xs font-semibold text-accent">Platform Setup</span>
            </div>
        </header>
        <main class="mx-auto max-w-6xl px-4 py-6">
            <slot />
            <ProductFooter class="mt-10 border-t border-line pt-4" />
        </main>
        <ToastHost />
    </div>
</template>
