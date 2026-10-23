<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import type { WorkHub, WorkSummary } from '@/types/shared';

/** Every workflow this person works in, with how much is waiting in each (the mobile "Work" tab). */
const hubs = ref<WorkHub[] | null>(null);
const error = ref<unknown>(null);
const waiting = (h: WorkHub) => h.queues.filter((q) => q.kind !== 'watch').reduce((n, q) => n + q.count, 0);

onMounted(async () => {
    try {
        hubs.value = (await get<{ data: WorkSummary }>('/api/v1/work')).data.hubs;
    } catch (e) {
        error.value = e;
    }
});
</script>

<template>
    <div>
        <PageHeader title="Work" subtitle="Choose what you are working on." />
        <ErrorSummary :error="error" />
        <div v-if="!hubs && !error" class="grid gap-3 sm:grid-cols-2" aria-busy="true">
            <div v-for="n in 4" :key="n" class="h-24 animate-pulse rounded-brand bg-surface" />
        </div>
        <div v-else-if="hubs" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Link v-for="h in hubs" :key="h.key" :href="h.href" class="flex items-start gap-4 rounded-brand border border-line bg-surface p-5 shadow-sm transition hover:shadow-md">
                <span class="grid size-12 shrink-0 place-items-center rounded-lg bg-brand/10 text-brand"><AppIcon :name="h.icon" /></span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center gap-2 font-semibold">
                        {{ h.label }}
                        <span v-if="waiting(h) > 0" class="rounded-full bg-accent px-2 text-xs font-bold text-accent-contrast tabular-nums">{{ waiting(h) }}</span>
                    </span>
                    <span class="mt-1 block text-sm text-ink-muted">{{ h.description }}</span>
                </span>
            </Link>
        </div>
    </div>
</template>
