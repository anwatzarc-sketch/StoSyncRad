<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import { useShared } from '@/composables/useShared';

/** Closing call to action of a product website page. */
withDefaults(defineProps<{ title?: string; text?: string }>(), {
    title: 'See your own stock flow in a live demo',
    text: 'Bring a few of your items, branches and a typical transfer. We will walk through them end to end and show what going live takes.',
});

const { brand } = useShared();
const product = computed(() => brand.value.product);
</script>

<template>
    <section class="mk-container py-20 sm:py-24">
        <div class="mk-hero relative isolate overflow-hidden rounded-3xl px-6 py-14 text-white shadow-xl sm:px-14">
            <div class="mk-grid pointer-events-none absolute inset-0 -z-10" aria-hidden="true" />
            <div class="grid items-center gap-8 lg:grid-cols-[1.5fr_1fr]">
                <div>
                    <h2 class="text-3xl font-bold tracking-tight text-balance sm:text-4xl">{{ title }}</h2>
                    <p class="mt-4 max-w-2xl text-lg leading-relaxed text-white/80">{{ text }}</p>
                </div>
                <div class="flex flex-wrap gap-3 lg:justify-end">
                    <Link href="/contact" class="btn btn-product min-h-12 px-6 text-base">Book a demo<AppIcon name="arrow-right" :size="18" /></Link>
                    <Link href="/features" class="btn btn-on-dark min-h-12 px-6 text-base">Explore {{ product.name }}</Link>
                </div>
            </div>
        </div>
    </section>
</template>
