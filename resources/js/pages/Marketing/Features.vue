<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import PageHero from '@/components/marketing/PageHero.vue';
import { useShared } from '@/composables/useShared';

/** Product website: every capability, grouped the way a company runs its stock. */
const { brand } = useShared();
const name = computed(() => brand.value.product.name);

const groups = [
    {
        id: 'purchasing', icon: 'inbox', title: 'Purchasing & receiving',
        lead: 'Buy from suppliers and receive stock the way regulated and well-run warehouses do.',
        items: [
            { title: 'Purchase orders', text: 'Orders per supplier and receiving warehouse, with approval rules and limits.' },
            { title: 'Goods receipt with QC', text: 'Receive against the order; inspection decides what becomes available, what is quarantined and what goes back.' },
            { title: 'Lot and expiry capture', text: 'Lot number, manufacture and expiry dates are recorded at the receipt line, before stock can be used.' },
            { title: 'Over-receipt control', text: 'Receiving more than ordered needs a specific permission, a reason and an audit record.' },
            { title: 'Supplier returns', text: 'Return damaged or rejected stock to the supplier with the exact lots.' },
            { title: 'Landed cost & ASNs', text: 'Optional: spread freight and duties onto receipts; pre-fill receipts from supplier shipment notices.' },
        ],
    },
    {
        id: 'lots', icon: 'tag', title: 'Lots, serials & expiry',
        lead: 'Batch control that follows each unit everywhere it goes.',
        items: [
            { title: 'FEFO picking', text: 'Pick lists suggest the earliest-expiring lots first; issuing expired stock needs an override.' },
            { title: 'Expiry monitor', text: 'Warnings before lots expire; expired stock moves to the expired condition automatically.' },
            { title: 'Recalls', text: 'A recall locks the affected lots in every branch at once so they cannot be sold or issued.' },
            { title: 'Serial tracking', text: 'Serial numbers captured at receipt, verified on transfer and recorded at sale.' },
            { title: 'Forward & backward trace', text: 'For any lot: every receipt, QC decision, location, transfer, sale, return and remaining quantity per branch.' },
            { title: 'Stock conditions', text: 'Available, quarantine, damaged, expired and blocked stock kept apart — and counted separately.' },
        ],
    },
    {
        id: 'distribution', icon: 'truck', title: 'Multi-branch distribution',
        lead: 'Headquarters, depots, stores and hubs working from the same ledger.',
        items: [
            { title: 'Branches & warehouses', text: 'Headquarters, retail stores, distribution depots, fulfilment hubs, production stores — each with its own warehouses, zones and bins.' },
            { title: 'Allocation plans', text: 'HQ plans how stock is distributed; approved plans generate the transfers.' },
            { title: 'Verified transfers', text: 'Exact lots recorded at dispatch, locked in transit and verified on receipt; differences are approved, not absorbed.' },
            { title: 'Internal requests & issues', text: 'Departments and branches request stock; storekeepers pick and issue against approved requests.' },
            { title: 'Relocation & condition changes', text: 'Move stock between bins, or between conditions, with a full movement record.' },
            { title: 'Reservations', text: 'Stock promised to orders and transfers is reserved, so available quantities are always honest.' },
        ],
    },
    {
        id: 'fulfilment', icon: 'cart', title: 'Sales & fulfilment',
        lead: 'The stock side of every sale — from the shop counter to the delivery van.',
        items: [
            { title: 'Retail sales', text: 'Customer sales orders and deliveries at the store, picked by expiry.' },
            { title: 'Pick, pack & ship', text: 'Pick waves, packing and shipments for e-commerce and delivery hubs, with carrier status updates.' },
            { title: 'Customer returns', text: 'Return authorisations, so returned stock comes back into the right condition and location.' },
            { title: 'Customer portal', text: 'Business customers browse the catalogue, request quotations, order, see invoices, confirm payments and track deliveries.' },
        ],
    },
    {
        id: 'counting', icon: 'clipboard', title: 'Stocktaking & adjustments',
        lead: 'Counts that people trust, and corrections that leave a trail.',
        items: [
            { title: 'Full, cycle & blind counts', text: 'Count the whole warehouse or a cycle by frequency; blind mode hides the expected quantity from counters.' },
            { title: 'Recounts & approval', text: 'Differences above your thresholds go to recount and approval before anything is posted.' },
            { title: 'Adjustments with reasons', text: 'Every write-off and correction carries a reason, an approver and its cost.' },
            { title: 'Reconciliation', text: 'Scheduled checks rebuild balances from the ledger and report any difference.' },
        ],
    },
    {
        id: 'planning', icon: 'refresh', title: 'Planning & replenishment',
        lead: 'Reorder from real consumption, not guesswork.',
        items: [
            { title: 'Demand history', text: 'Consumption, sales, lead times and discrepancies by day, week, month, quarter or year.' },
            { title: 'Reorder points & min/max', text: 'Suggestions per item and warehouse from your reorder rules, counting what is available, inbound and already committed.' },
            { title: 'From suggestion to order', text: 'Turn a suggestion into a draft purchase request or transfer that follows the normal approval workflow.' },
        ],
    },
    {
        id: 'valuation', icon: 'coins', title: 'Inventory valuation',
        lead: 'Know what your stock is worth, everywhere, at any date.',
        items: [
            { title: 'Costing methods', text: 'Moving average, FIFO layers or standard cost — per item or inherited from the category.' },
            { title: 'Cost on every movement', text: 'Each posted line carries its unit and total cost, so value follows quantity exactly.' },
            { title: 'Valuation reports', text: 'Value by item and warehouse, FIFO layers and movement history at any reporting date.' },
            { title: 'Protected cost data', text: 'Costs are shown only to roles that may see them — on screen, in exports and in reports.' },
        ],
    },
    {
        id: 'reporting', icon: 'chart', title: 'Custom reporting',
        lead: 'A report builder for operations people, with guard rails for IT.',
        items: [
            { title: 'Dataset catalogue', text: 'Stock positions, movements, lots, transfers, receipts, sales, counts, valuation and more.' },
            { title: 'Builder', text: 'Choose columns, filters, grouping, sorting and totals; save and share report definitions.' },
            { title: 'Web, PDF, Excel & CSV', text: 'On-screen results with drill-down, letterhead PDFs and typed Excel files; large exports run in the background.' },
            { title: 'Safe by design', text: 'Reports run read-only, within time and size limits, and only over data the person may see.' },
        ],
    },
    {
        id: 'mobile', icon: 'smartphone', title: 'Mobile & scanning',
        lead: 'One app for the office and the floor.',
        items: [
            { title: 'Installable app', text: 'Add it to the home screen on phones, tablets and desktops — no app store needed.' },
            { title: 'Barcode scanning', text: 'Use the device camera or a scanner to find items, lots and locations.' },
            { title: 'Task screens', text: 'Focused screens for dispatching, receiving, counting and picking.' },
            { title: 'Printed documents', text: 'Purchase orders, goods receipts, issues, pick lists, delivery notes and count sheets with your letterhead, barcodes and QR codes.' },
        ],
    },
    {
        id: 'administration', icon: 'settings', title: 'Administration & integration',
        lead: 'Configure everything; change code for nothing.',
        items: [
            { title: 'Guided setup', text: 'A step-by-step setup wizard — or a configuration bundle prepared in advance — from company profile to opening data.' },
            { title: 'Roles you design', text: 'Build roles from individual permissions and assign them per branch or warehouse.' },
            { title: 'Policies as settings', text: 'Approval rules, separation of duties, numbering, stock rules and limits are configuration.' },
            { title: 'Your brand', text: 'Company name, colours, logo, letterhead, email templates and app icon — no rebuild.' },
            { title: 'Imports & exports', text: 'Spreadsheet templates for items, partners and opening balances, checked before anything is posted.' },
            { title: 'API & webhooks', text: 'A versioned REST API with scoped tokens, plus signed webhooks for events in other systems.' },
            { title: 'Notifications', text: 'In-app and email notifications for approvals, expiry, discrepancies and more — with digests.' },
        ],
    },
];
</script>

<template>
    <div>
        <Head title="Features" />
        <PageHero
            eyebrow="Features"
            :title="`Everything ${name} does for your stock`"
            lead="From the purchase order to the customer's door — and every count, correction and report in between. Switch on what you need; every feature follows the same ledger, permissions and audit trail."
        >
            <Link href="/contact" class="btn btn-product min-h-12 px-6 text-base">Book a demo<AppIcon name="arrow-right" :size="18" /></Link>
        </PageHero>

        <nav class="sticky top-16 z-30 border-b border-line bg-surface/90 backdrop-blur" aria-label="Feature groups">
            <div class="mk-container flex gap-2 overflow-x-auto py-3 [scrollbar-width:none]">
                <a v-for="g in groups" :key="g.id" :href="`#${g.id}`" class="shrink-0 rounded-full border border-line px-3 py-1.5 text-sm font-medium whitespace-nowrap text-ink-muted transition hover:border-product-accent/50 hover:text-ink">
                    {{ g.title }}
                </a>
            </div>
        </nav>

        <div class="mk-container divide-y divide-line">
            <section v-for="g in groups" :id="g.id" :key="g.id" class="grid scroll-mt-32 gap-10 py-16 sm:py-20 lg:grid-cols-[1fr_2fr]">
                <div class="lg:sticky lg:top-36 lg:self-start">
                    <span class="grid size-12 place-items-center rounded-2xl bg-product-ink text-white shadow-sm"><AppIcon :name="g.icon" :size="24" /></span>
                    <h2 class="mt-5 text-2xl font-bold tracking-tight sm:text-3xl">{{ g.title }}</h2>
                    <p class="mt-3 leading-relaxed text-ink-muted">{{ g.lead }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <article v-for="item in g.items" :key="item.title" class="rounded-2xl border border-line bg-surface p-6 shadow-sm transition hover:border-product-accent/40 hover:shadow-md">
                        <h3 class="flex items-center gap-2 font-semibold">
                            <span class="grid size-5 place-items-center rounded-full bg-product-accent/10 text-product-accent"><AppIcon name="check" :size="12" /></span>
                            {{ item.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ item.text }}</p>
                    </article>
                </div>
            </section>
        </div>

        <CtaBand title="Pick the features you need today" text="Optional capabilities — retail sales, e-commerce fulfilment, kits, landed cost, production stores, the customer portal — are switched on when you are ready, without a new installation." />
    </div>
</template>
