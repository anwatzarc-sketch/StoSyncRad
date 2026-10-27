<script setup lang="ts">
import { useId } from 'vue';
import UiButton from '@/components/ui/UiButton.vue';
import type { NextAction } from '@/types/shared';

/**
 * A screen's "Next action": the one thing that usually comes next in the workflow (first, as the
 * primary button, with a line saying why) and a few alternatives. Navigation only — every action is
 * checked again where it lands. Renders nothing when there is no next step.
 */
withDefaults(defineProps<{ actions: NextAction[]; title?: string }>(), { title: 'Next action' });

const id = useId();
</script>

<template>
    <section
        v-if="actions.length"
        class="card mb-6 flex flex-wrap items-center gap-x-4 gap-y-3 border-l-4 border-l-brand py-3"
        :aria-labelledby="id"
    >
        <div class="min-w-0 flex-1 basis-52">
            <h2 :id="id" class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ title }}</h2>
            <p v-if="actions[0].hint" class="mt-0.5 text-sm">{{ actions[0].hint }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <UiButton
                v-for="(a, i) in actions"
                :key="a.label"
                :variant="i === 0 ? 'primary' : 'secondary'"
                :icon="a.icon"
                :href="a.href"
                @click="a.onClick?.()"
            >
                {{ a.label }}
            </UiButton>
        </div>
    </section>
</template>
