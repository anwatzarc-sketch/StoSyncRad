<script setup lang="ts">
import { computed } from 'vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { enumLabel } from '@/lib/meta';
import { stepIndex } from '@/lib/workflow';
import type { WorkflowMeta } from '@/types/shared';

/**
 * Where a document stands on its happy path (e.g. Draft → Submitted → Approved → Posted). A status off
 * the path (cancelled, rejected, under review…) is shown as a badge instead. Display only.
 */
const props = defineProps<{ workflow: WorkflowMeta; status: string | null | undefined; statusLabel?: string | null }>();

const at = computed(() => stepIndex(props.workflow, props.status));
const label = (status: string) => enumLabel(props.workflow.status_enum, status);
</script>

<template>
    <div class="card">
        <div v-if="at === -1 && status" class="mb-3 flex items-center gap-2 text-sm">
            <span class="text-ink-muted">This document is</span>
            <StatusBadge :value="status" :label="statusLabel ?? label(status)" />
        </div>
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-3" aria-label="Progress">
            <li v-for="(step, i) in workflow.steps" :key="step" class="flex items-center gap-2">
                <span
                    :class="['flex items-center gap-2 rounded-full px-3 py-1.5 text-sm',
                             i === at ? 'bg-brand font-semibold text-brand-contrast' : i < at ? 'bg-success/10 text-success' : 'bg-surface-muted text-ink-muted']"
                    :aria-current="i === at ? 'step' : undefined"
                >
                    <AppIcon v-if="i < at" name="check" :size="14" />
                    <span v-else class="grid size-5 place-items-center rounded-full text-xs font-bold" :class="i === at ? 'bg-white/25' : 'bg-surface'">{{ i + 1 }}</span>
                    {{ label(step) }}
                </span>
                <AppIcon v-if="i < workflow.steps.length - 1" name="chevron-right" :size="14" class="text-ink-muted" />
            </li>
        </ol>
    </div>
</template>
