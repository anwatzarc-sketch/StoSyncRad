<script setup lang="ts">
/** Heading block of a product website section. */
withDefaults(defineProps<{ eyebrow?: string; title: string; lead?: string; center?: boolean }>(), { eyebrow: undefined, lead: undefined, center: false });
</script>

<template>
    <div :class="['max-w-3xl', center ? 'mx-auto text-center' : '']">
        <p v-if="eyebrow" class="mk-eyebrow">{{ eyebrow }}</p>
        <h2 class="mt-3 text-3xl font-bold tracking-tight text-balance sm:text-4xl">{{ title }}</h2>
        <p v-if="lead" class="mt-4 text-lg leading-relaxed text-ink-muted text-pretty">{{ lead }}</p>
    </div>
</template>
