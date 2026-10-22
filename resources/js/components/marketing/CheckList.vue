<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue';

/** Bulleted list with check marks; `dark` for navy backgrounds. */
withDefaults(defineProps<{ items: string[]; dark?: boolean }>(), { dark: false });
</script>

<template>
    <ul class="space-y-3">
        <li v-for="item in items" :key="item" class="flex gap-3">
            <span :class="['mt-0.5 grid size-5 shrink-0 place-items-center rounded-full', dark ? 'bg-product-teal/20 text-product-teal' : 'bg-product-accent/10 text-product-accent']">
                <AppIcon name="check" :size="13" />
            </span>
            <span :class="['text-sm leading-relaxed', dark ? 'text-white/80' : 'text-ink-muted']">{{ item }}</span>
        </li>
    </ul>
</template>
