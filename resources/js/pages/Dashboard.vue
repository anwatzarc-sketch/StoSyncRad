<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { useShared } from '@/composables/useShared';
import type { WorkQueue, WorkSummary } from '@/types/shared';

/**
 * Home — "My work" (Chapter 12 §12.6): what this person can start, what is waiting for them (counted
 * by the server where they may perform the awaited action; approvals first) and a few signals worth
 * a look. One request: GET /api/v1/work.
 */
const { auth } = useShared();
const work = ref<WorkSummary | null>(null);
const error = ref<unknown>(null);

const firstName = computed(() => (auth.value?.account.display_name ?? '').split(' ')[0]);
const greeting = computed(() => {
    const h = new Date().getHours();

    return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
});
const hubIcon = computed(() => Object.fromEntries((work.value?.hubs ?? []).map((h) => [h.key, h.icon])));
const hubLabel = computed(() => Object.fromEntries((work.value?.hubs ?? []).map((h) => [h.key, h.label])));
const approvals = computed(() => (work.value?.waiting ?? []).filter((q) => q.kind === 'approve'));
const todo = computed(() => (work.value?.waiting ?? []).filter((q) => q.kind !== 'approve'));
const alerts = computed(() => (work.value?.alerts ?? []).filter((a) => a.count > 0));
const caughtUp = computed(() => work.value !== null && work.value.waiting.length === 0);
const icon = (q: WorkQueue) => (q.kind === 'approve' ? 'check' : q.hub ? hubIcon.value[q.hub] ?? 'clipboard' : 'clipboard');

onMounted(async () => {
    try {
        work.value = (await get<{ data: WorkSummary }>('/api/v1/work')).data;
    } catch (e) {
        error.value = e;
    }
});
</script>

<template>
    <div>
        <Head title="Home" />
        <header class="mb-6">
            <h1 class="text-2xl font-bold text-ink">{{ greeting }}<template v-if="firstName">, {{ firstName }}</template></h1>
            <p class="mt-1 text-sm text-ink-muted">Here is what needs you today.</p>
        </header>
        <ErrorSummary :error="error" />

        <!-- Start something -->
        <section v-if="work?.start.length" class="mb-8" aria-labelledby="start-title">
            <h2 id="start-title" class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">Start</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                <Link
                    v-for="s in work.start"
                    :key="s.href"
                    :href="s.href"
                    class="group flex min-h-24 flex-col justify-between rounded-brand border border-line bg-surface p-4 shadow-sm transition hover:border-brand/40 hover:shadow-md"
                >
                    <span class="grid size-10 place-items-center rounded-lg bg-brand/10 text-brand"><AppIcon :name="s.icon" /></span>
                    <span class="mt-3 text-sm font-semibold leading-snug">{{ s.label }}</span>
                </Link>
            </div>
        </section>

        <!-- Loading -->
        <div v-if="!work && !error" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-busy="true">
            <div v-for="n in 6" :key="n" class="h-24 animate-pulse rounded-brand bg-surface" />
        </div>

        <!-- Waiting for you -->
        <section v-if="approvals.length" class="mb-8" aria-labelledby="approvals-title">
            <h2 id="approvals-title" class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">Waiting for your approval</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link v-for="q in approvals" :key="q.key" :href="q.href" class="flex items-center gap-4 rounded-brand border border-accent/30 bg-surface p-4 shadow-sm transition hover:shadow-md">
                    <span class="grid size-12 shrink-0 place-items-center rounded-full bg-accent/10 text-xl font-bold text-accent tabular-nums">{{ q.count }}</span>
                    <span class="min-w-0">
                        <span class="block font-semibold leading-snug">{{ q.label }}</span>
                        <span v-if="q.hub" class="block text-xs text-ink-muted">{{ hubLabel[q.hub] }}</span>
                    </span>
                    <AppIcon name="chevron-right" class="ml-auto text-ink-muted" />
                </Link>
            </div>
        </section>

        <section v-if="todo.length" class="mb-8" aria-labelledby="todo-title">
            <h2 id="todo-title" class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">To do</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link v-for="q in todo" :key="q.key" :href="q.href" class="flex items-center gap-4 rounded-brand border border-line bg-surface p-4 shadow-sm transition hover:shadow-md">
                    <span class="grid size-12 shrink-0 place-items-center rounded-full bg-brand/10 text-brand"><AppIcon :name="icon(q)" /></span>
                    <span class="min-w-0">
                        <span class="block font-semibold leading-snug">{{ q.label }}</span>
                        <span class="block text-xs text-ink-muted"><span class="font-semibold tabular-nums text-ink">{{ q.count }}</span><template v-if="q.hub"> · {{ hubLabel[q.hub] }}</template></span>
                    </span>
                    <AppIcon name="chevron-right" class="ml-auto text-ink-muted" />
                </Link>
            </div>
        </section>

        <div v-if="caughtUp" class="card mb-8 flex items-center gap-4 p-6">
            <span class="grid size-12 shrink-0 place-items-center rounded-full bg-success/10 text-success"><AppIcon name="check" /></span>
            <div>
                <p class="font-semibold">You are all caught up</p>
                <p class="text-sm text-ink-muted">Nothing is waiting for you right now.</p>
            </div>
        </div>

        <!-- Keep an eye on -->
        <section v-if="alerts.length" aria-labelledby="alerts-title">
            <h2 id="alerts-title" class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">Keep an eye on</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <component
                    :is="a.href ? Link : 'div'"
                    v-for="a in alerts"
                    :key="a.key"
                    :href="a.href ?? undefined"
                    class="flex items-center gap-3 rounded-brand border border-warning/30 bg-warning/5 p-4"
                >
                    <AppIcon :name="a.icon" class="text-warning" />
                    <span class="min-w-0 text-sm"><span class="font-bold tabular-nums">{{ a.count }}</span> {{ a.label.toLowerCase() }}</span>
                </component>
            </div>
        </section>
    </div>
</template>
