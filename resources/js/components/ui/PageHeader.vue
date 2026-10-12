<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ title: string; subtitle?: string | null; back?: string | null }>();
</script>

<template>
    <Head :title="title" />
    <header class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <Link v-if="back" :href="back" class="mb-1 inline-flex min-h-8 items-center text-sm text-ink-muted hover:text-ink">← Back</Link>
            <h1 class="truncate text-2xl font-bold text-ink">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 text-sm text-ink-muted">{{ subtitle }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
