<script setup lang="ts">
import { computed } from 'vue';
import { humanize } from '@/lib/meta';

/** Status with text + tone (never colour alone, Chapter 12 §12.10). */
const props = defineProps<{ value: string | null | undefined; label?: string | null }>();

const tone = computed(() => {
    const v = props.value ?? '';
    if (/(posted|approved|completed|received|delivered|closed|active|released|locked|pass|ok|processed|shipped)/.test(v)) {
        return 'bg-success/10 text-success ring-success/30';
    }
    if (/(rejected|cancelled|failed|dead|blacklisted|blocked|expired|fail|error|suspended|deactivated)/.test(v)) {
        return 'bg-danger/10 text-danger ring-danger/30';
    }
    if (/(pending|submitted|review|warn|on_hold|in_transit|partially|picking|counting|draft|queued|running|scheduled|in_progress)/.test(v)) {
        return 'bg-warning/10 text-warning ring-warning/30';
    }

    return 'bg-surface-muted text-ink-muted ring-line';
});
</script>

<template>
    <span v-if="value" :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset', tone]">
        {{ label || humanize(value) }}
    </span>
</template>
