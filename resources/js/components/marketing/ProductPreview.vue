<script setup lang="ts">
import { computed } from 'vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import { useShared } from '@/composables/useShared';

/** Illustration of the stock positions screen for the product website (sample data, not live). */
const { brand } = useShared();
const product = computed(() => brand.value.product);

const kpis = [
    { label: 'On hand', value: '12,480' },
    { label: 'Reserved', value: '1,126' },
    { label: 'In transit', value: '860' },
    { label: 'Quarantine', value: '42' },
];
const rows = [
    { item: 'Amoxicillin 500 mg caps', lot: 'AMX-2604A', expiry: 'Mar 2028', tone: 'ok', where: 'HQ · A-03-2', qty: '2,400' },
    { item: 'Paracetamol 500 mg tabs', lot: 'PCM-2511B', expiry: 'Nov 2027', tone: 'ok', where: 'Depot · B-11-1', qty: '5,120' },
    { item: 'ORS sachets', lot: 'ORS-2507A', expiry: 'Feb 2027', tone: 'warn', where: 'Store 2 · S-01', qty: '780' },
    { item: 'Insulin glargine pen', lot: 'INS-2601C', expiry: 'Dec 2026', tone: 'soon', where: 'HQ · Cold-02', qty: '64' },
];
const tones: Record<string, string> = {
    ok: 'bg-success/10 text-success',
    warn: 'bg-warning/10 text-warning',
    soon: 'bg-danger/10 text-danger',
};
</script>

<template>
    <div class="relative" role="img" :aria-label="`Illustration of the ${product.name} stock positions screen with lots, expiry dates and locations`">
        <div class="overflow-hidden rounded-2xl border border-white/15 bg-surface text-ink shadow-2xl ring-1 ring-black/5" aria-hidden="true">
            <div class="flex items-center gap-2 border-b border-line bg-surface-muted px-4 py-3">
                <span class="size-2.5 rounded-full bg-danger/60" /><span class="size-2.5 rounded-full bg-warning/60" /><span class="size-2.5 rounded-full bg-success/60" />
                <span class="ml-3 truncate text-xs font-medium text-ink-muted">{{ product.name }} · Stock positions</span>
            </div>
            <div class="grid grid-cols-[3rem_1fr]">
                <div class="flex flex-col items-center gap-4 bg-product-ink py-4 text-white/70">
                    <AppIcon name="home" :size="18" /><AppIcon name="boxes" :size="18" class="text-product-teal" /><AppIcon name="truck" :size="18" />
                    <AppIcon name="clipboard" :size="18" /><AppIcon name="chart" :size="18" />
                </div>
                <div class="min-w-0 p-4">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div v-for="k in kpis" :key="k.label" class="rounded-lg border border-line p-2.5">
                            <p class="text-[0.65rem] font-medium tracking-wide text-ink-muted uppercase">{{ k.label }}</p>
                            <p class="mt-0.5 text-lg font-bold tabular-nums">{{ k.value }}</p>
                        </div>
                    </div>
                    <div class="mt-4 overflow-hidden rounded-lg border border-line">
                        <div class="grid grid-cols-[1.6fr_1fr_0.7fr] gap-2 bg-surface-muted px-3 py-2 text-[0.65rem] font-semibold tracking-wide text-ink-muted uppercase">
                            <span>Item · lot</span><span>Location</span><span class="text-right">Qty</span>
                        </div>
                        <div v-for="r in rows" :key="r.lot" class="grid grid-cols-[1.6fr_1fr_0.7fr] items-center gap-2 border-t border-line px-3 py-2.5 text-xs">
                            <div class="min-w-0">
                                <p class="truncate font-semibold">{{ r.item }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-ink-muted">
                                    <span class="font-mono">{{ r.lot }}</span>
                                    <span :class="['rounded-full px-1.5 py-px text-[0.6rem] font-semibold', tones[r.tone]]">Exp {{ r.expiry }}</span>
                                </p>
                            </div>
                            <span class="truncate text-ink-muted">{{ r.where }}</span>
                            <span class="text-right font-semibold tabular-nums">{{ r.qty }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="absolute -bottom-14 -left-6 hidden w-64 rounded-xl border border-line bg-surface p-3 text-ink shadow-xl sm:block" aria-hidden="true">
            <div class="flex items-center gap-3">
                <span class="grid size-9 place-items-center rounded-lg bg-product-accent/10 text-product-accent"><AppIcon name="truck" :size="18" /></span>
                <div class="min-w-0">
                    <p class="truncate text-xs font-semibold">Transfer TR-000124</p>
                    <p class="text-[0.7rem] text-ink-muted">In transit · 3 lots locked</p>
                </div>
            </div>
            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-muted"><div class="h-full w-2/3 rounded-full bg-product-accent" /></div>
        </div>
        <div class="absolute -top-5 right-6 hidden rounded-xl border border-line bg-surface px-3 py-2 text-ink shadow-xl md:block" aria-hidden="true">
            <p class="flex items-center gap-2 text-xs font-semibold"><span class="grid size-5 place-items-center rounded-full bg-success/15 text-success"><AppIcon name="check" :size="12" /></span>Receipt posted · QC released</p>
        </div>
    </div>
</template>
