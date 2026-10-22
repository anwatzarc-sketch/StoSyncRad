<script setup lang="ts">
/** Navy page header of a product website page: eyebrow, title, lead and optional actions. */
defineProps<{ eyebrow: string; title: string; lead: string }>();
</script>

<template>
    <section class="mk-hero relative isolate overflow-hidden text-white">
        <div class="mk-grid pointer-events-none absolute inset-0 -z-10" aria-hidden="true" />
        <div class="mk-container py-20 sm:py-24">
            <p class="text-xs font-semibold tracking-[0.16em] text-product-teal uppercase motion-safe:animate-rise">{{ eyebrow }}</p>
            <h1 class="mt-4 max-w-3xl text-4xl font-bold tracking-tight text-balance sm:text-5xl motion-safe:animate-rise">{{ title }}</h1>
            <p class="mt-6 max-w-2xl text-lg leading-relaxed text-white/80 text-pretty motion-safe:animate-rise">{{ lead }}</p>
            <div v-if="$slots.default" class="mt-8 flex flex-wrap gap-3 motion-safe:animate-rise">
                <slot />
            </div>
        </div>
    </section>
</template>
