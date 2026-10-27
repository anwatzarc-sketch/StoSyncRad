<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppIcon from './AppIcon.vue';
import NextActions from '@/components/NextActions.vue';
import { hasInAppHistory, homeFor, parentOf } from '@/lib/navigation';
import type { NextAction } from '@/types/shared';

/**
 * Page title with Back and Home on every page, the page's own actions, and its "Next action".
 * Back defaults to the page's logical parent (lib/navigation); pages pass `back` / `back-label`
 * when they know a better one. On a top-level page Back returns to the previous page of this visit.
 */
const props = withDefaults(defineProps<{
    title: string;
    subtitle?: string | null;
    back?: string | null;
    backLabel?: string | null;
    next?: NextAction[];
}>(), { subtitle: null, back: null, backLabel: null, next: () => [] });

const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const home = computed(() => homeFor(path.value));
const parent = computed(() => {
    const logical = parentOf(path.value);
    if (props.back) {
        return { href: props.back, label: props.backLabel ?? (logical?.href === props.back ? logical.label : 'Back') };
    }

    return logical;
});
/** Top-level pages (whose parent is Home) go back through history when this visit has one. */
const throughHistory = computed(() => !props.back && parent.value?.href === home.value.href && hasInAppHistory());
const goBack = () => window.history.back();
</script>

<template>
    <Head :title="title" />
    <header class="mb-5">
        <nav v-if="parent" class="mb-2 flex flex-wrap items-center gap-x-1 text-sm text-ink-muted" aria-label="Back and home">
            <button v-if="throughHistory" type="button" class="inline-flex min-h-9 items-center gap-1 rounded pr-2 hover:text-ink" @click="goBack">
                <AppIcon name="chevron-left" :size="16" /> Back
            </button>
            <Link v-else :href="parent.href" class="inline-flex min-h-9 items-center gap-1 rounded pr-2 hover:text-ink">
                <AppIcon name="chevron-left" :size="16" /> {{ parent.label === 'Back' ? 'Back' : `Back to ${parent.label}` }}
            </Link>
            <span aria-hidden="true" class="text-line">|</span>
            <Link :href="home.href" class="inline-flex min-h-9 items-center gap-1 rounded px-2 hover:text-ink">
                <AppIcon name="home" :size="15" /> {{ home.label }}
            </Link>
        </nav>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h1 class="truncate text-2xl font-bold text-ink">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1 text-sm text-ink-muted">{{ subtitle }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <slot name="actions" />
            </div>
        </div>
    </header>
    <NextActions :actions="next" />
</template>
