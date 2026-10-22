<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import CheckList from '@/components/marketing/CheckList.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import PageHero from '@/components/marketing/PageHero.vue';
import { useShared } from '@/composables/useShared';

/** Product website: how the product fits each kind of business. */
const { brand } = useShared();
const name = computed(() => brand.value.product.name);

const solutions = [
    {
        id: 'pharma', icon: 'pill', title: 'Pharmaceutical distributors & pharmacies',
        lead: 'Medicines must be traceable by batch, released by QC and sold before they expire.',
        challenges: ['Lots and expiry dates tracked on paper or in spreadsheets', 'Expired stock discovered on the shelf, not in a report', 'Recalls that take days to locate in every branch'],
        answers: ['Lot, manufacture and expiry dates captured at receipt; QC release before stock is available', 'Earliest-expiry-first picking, expiry warnings and automatic expired condition', 'A recall locks the affected lots in every branch at once, with a full forward trace'],
        tags: ['Lots & expiry', 'Quarantine & QC', 'Recalls', 'FEFO', 'Traceability'],
    },
    {
        id: 'retail', icon: 'store', title: 'Retail chains',
        lead: 'Headquarters buys and distributes; every store sells — and every store must add up.',
        challenges: ['Stores that cannot see each other\'s stock', 'Deliveries from HQ that do not match what arrived', 'Counts that disagree with the system and nobody knows why'],
        answers: ['One ledger: on hand, reserved and in transit for every store, live', 'Transfers verified at both ends with the exact lots; differences approved and traceable', 'Cycle and blind counts with recount thresholds and approval before posting'],
        tags: ['Allocation plans', 'Verified transfers', 'Retail sales', 'Cycle counts'],
    },
    {
        id: 'distribution', icon: 'truck', title: 'Wholesale & distribution',
        lead: 'High volumes, many warehouses and business customers who expect accurate promises.',
        challenges: ['Available-to-promise figures that ignore what is already committed', 'Pickers searching bins instead of following a list', 'Customers calling to ask about orders, invoices and deliveries'],
        answers: ['Reservations keep available quantities honest across warehouses', 'Zones and bins, pick lists in expiry order and scan-based task screens', 'A customer portal for catalogue, quotations, orders, invoices, payment confirmation and delivery tracking'],
        tags: ['Multi-warehouse', 'Pick lists', 'Customer portal', 'Reservations'],
    },
    {
        id: 'healthcare', icon: 'building', title: 'Hospitals, clinics & institutional stores',
        lead: 'Central stores supply wards and departments that cannot run out.',
        challenges: ['Department requests on paper with no approval trail', 'Consumption that is invisible until stock runs out', 'Expired supplies written off without a reason'],
        answers: ['Internal requests and issues with approval rules and separation of duties', 'Consumption history and reorder points that suggest what to buy or transfer', 'Every adjustment carries a reason, an approver and its cost'],
        tags: ['Requests & issues', 'Approvals', 'Replenishment', 'Expiry control'],
    },
    {
        id: 'ecommerce', icon: 'package', title: 'E-commerce fulfilment',
        lead: 'Orders come in fast and customers expect them to leave the same day.',
        challenges: ['Orders picked one by one across a busy warehouse', 'Shipments that cannot be tracked after they leave', 'Returns that disappear into a corner of the warehouse'],
        answers: ['Pick waves in expiry order, packing and shipments', 'Carrier status updates on shipments from connected carriers', 'Return authorisations that bring stock back into the right condition'],
        tags: ['Pick waves', 'Packing', 'Shipments', 'Returns'],
    },
    {
        id: 'production', icon: 'factory', title: 'Production stores',
        lead: 'Raw materials go to production; finished goods come back into stock.',
        challenges: ['Materials issued to production without a record of which lot', 'Finished goods counted by hand at the end of the day'],
        answers: ['Issue to production with the exact lots consumed', 'Receive finished goods into stock with their own lots and expiry dates'],
        tags: ['Production issue', 'Finished goods receipt', 'Lot tracking'],
    },
];
</script>

<template>
    <div>
        <Head title="Solutions" />
        <PageHero
            eyebrow="Solutions"
            :title="`One platform, shaped to the way your business moves stock`"
            :lead="`${name} is configured, not customised: the same product runs a pharmacy chain, a regional distributor or a hospital store — with the branches, policies and features each one needs.`"
        />

        <nav class="border-b border-line bg-surface" aria-label="Industries">
            <div class="mk-container flex flex-wrap gap-2 py-4">
                <a v-for="s in solutions" :key="s.id" :href="`#${s.id}`" class="inline-flex items-center gap-2 rounded-full border border-line px-3 py-1.5 text-sm font-medium text-ink-muted transition hover:border-product-accent/50 hover:text-ink">
                    <AppIcon :name="s.icon" :size="15" />{{ s.title }}
                </a>
            </div>
        </nav>

        <div class="mk-container space-y-8 py-16 sm:py-20">
            <section v-for="s in solutions" :id="s.id" :key="s.id" class="scroll-mt-24 overflow-hidden rounded-3xl border border-line bg-surface shadow-sm">
                <div class="grid lg:grid-cols-[1fr_1.4fr]">
                    <div class="mk-hero relative isolate p-8 text-white sm:p-10">
                        <div class="mk-grid pointer-events-none absolute inset-0 -z-10" aria-hidden="true" />
                        <span class="grid size-12 place-items-center rounded-2xl bg-white/10 text-product-teal"><AppIcon :name="s.icon" :size="24" /></span>
                        <h2 class="mt-6 text-2xl font-bold tracking-tight text-balance sm:text-3xl">{{ s.title }}</h2>
                        <p class="mt-3 leading-relaxed text-white/80">{{ s.lead }}</p>
                        <ul class="mt-6 flex flex-wrap gap-2">
                            <li v-for="t in s.tags" :key="t" class="rounded-full border border-white/20 bg-white/5 px-3 py-1 text-xs font-medium text-white/90">{{ t }}</li>
                        </ul>
                    </div>
                    <div class="grid gap-8 p-8 sm:p-10 md:grid-cols-2">
                        <div>
                            <h3 class="text-sm font-semibold tracking-wide text-ink-muted uppercase">The challenge</h3>
                            <ul class="mt-4 space-y-3">
                                <li v-for="c in s.challenges" :key="c" class="flex gap-3 text-sm leading-relaxed text-ink-muted">
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-danger/10 text-danger"><AppIcon name="x" :size="12" /></span>{{ c }}
                                </li>
                            </ul>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold tracking-wide text-ink-muted uppercase">How {{ name }} helps</h3>
                            <CheckList class="mt-4" :items="s.answers" />
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <section class="mk-container pb-4">
            <div class="rounded-3xl border border-line bg-surface-muted p-8 sm:p-10">
                <div class="grid items-center gap-6 md:grid-cols-[1fr_auto]">
                    <div>
                        <h2 class="text-2xl font-bold tracking-tight">Run more than one of these?</h2>
                        <p class="mt-2 max-w-2xl leading-relaxed text-ink-muted">
                            Most companies do. A headquarters, a depot, a few stores and an online channel are simply different branch kinds in the same {{ name }} — one ledger, one catalogue, one set of roles.
                        </p>
                    </div>
                    <Link href="/features" class="btn btn-secondary">See all features<AppIcon name="arrow-right" :size="16" /></Link>
                </div>
            </div>
        </section>

        <CtaBand />
    </div>
</template>
