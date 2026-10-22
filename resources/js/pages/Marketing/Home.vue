<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import CheckList from '@/components/marketing/CheckList.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import ProductPreview from '@/components/marketing/ProductPreview.vue';
import SectionIntro from '@/components/marketing/SectionIntro.vue';
import { useShared } from '@/composables/useShared';

/** Product website home page (MarketingController). */
const { brand } = useShared();
const name = computed(() => brand.value.product.name);

const facts = [
    { value: 'One ledger', text: 'for every branch, warehouse and bin' },
    { value: 'FEFO', text: 'picking by earliest expiry, automatically' },
    { value: '3 costing methods', text: 'moving average, FIFO or standard' },
    { value: 'PDF · Excel · CSV', text: 'reports built from any dataset' },
];

const pillars = [
    { icon: 'layers', title: 'Accuracy first', text: 'Every change is a posted, traceable movement. Posted records are never edited — corrections are reversals — so every balance can be rebuilt from the ledger at any time.' },
    { icon: 'shield', title: 'Control at every step', text: 'Approvals, approval limits and separation of duties are enforced on the server. People see and do only what their role allows, in the branches they are assigned to.' },
    { icon: 'eye', title: 'Visibility you can act on', text: 'On hand, reserved, available, in transit, quarantined, damaged and expired — per location, branch and company-wide, with the lots and serials behind every number.' },
];

const flow = [
    { icon: 'chart', title: 'Plan & buy', text: 'Demand history, reorder points and purchase orders.' },
    { icon: 'inbox', title: 'Receive & inspect', text: 'Lot, manufacture and expiry captured; QC before stock is available.' },
    { icon: 'boxes', title: 'Store', text: 'Warehouses, zones and bins with condition tracking.' },
    { icon: 'truck', title: 'Allocate & transfer', text: 'HQ allocation to branches; lots locked in transit.' },
    { icon: 'cart', title: 'Sell & fulfil', text: 'Retail sales, pick–pack–ship and customer orders.' },
    { icon: 'clipboard', title: 'Count & trace', text: 'Full, cycle and blind counts; trace any lot end to end.' },
];

const lotTimeline = [
    { when: 'Mar 04', what: 'Received at HQ', detail: 'GRN-000318 · 2,400 caps · Exp 03/2028' },
    { when: 'Mar 04', what: 'QC released', detail: 'Quarantine → available' },
    { when: 'Mar 06', what: 'Transferred to Depot', detail: 'TR-000124 · verified on receipt' },
    { when: 'Mar 11', what: 'Sold at Store 2', detail: 'SO-001045 · picked FEFO' },
];

const segments = [
    { id: 'pharma', icon: 'pill', title: 'Pharmaceutical distribution', text: 'Lot and expiry control from the receiving dock, QC release, recalls that lock stock everywhere.' },
    { id: 'retail', icon: 'store', title: 'Retail chains', text: 'HQ allocation to stores, verified store receiving and a live view of every shelf.' },
    { id: 'distribution', icon: 'truck', title: 'Wholesale & distribution', text: 'Multi-warehouse depots, pick lists, and a customer portal for B2B orders.' },
    { id: 'healthcare', icon: 'building', title: 'Hospitals & institutions', text: 'Department requests and issues, approvals, and consumption that drives reordering.' },
];

const floor = [
    'Installs like an app on phones, tablets and desktops — no app store needed',
    'Camera barcode scanning in supported browsers',
    'Task screens for dispatch, receiving, counting and picking',
    'Large touch targets and keyboard-friendly forms',
];

const faqs = computed(() => [
    { q: `Can ${name.value} run more than one branch?`, a: 'Yes — that is what it is built for. One company runs any number of branches (headquarters, retail stores, distribution depots, fulfilment hubs, production stores) and warehouses from one ledger, with permissions scoped per branch and warehouse.' },
    { q: 'Do we need to change our item codes or spreadsheets?', a: 'No. Items, partners and opening balances are imported from spreadsheet templates, checked as drafts, and posted only after review. Your existing codes and units are kept.' },
    { q: 'How are lots and expiry dates handled?', a: 'Lot number, manufacture date and expiry are captured at receipt. Picking follows earliest-expiry-first, expiring lots raise warnings, expired stock is moved to the expired condition, and a recall locks the affected lots in every branch.' },
    { q: 'Can we use our own name and logo?', a: 'Yes. Company name, colours, logo, document letterhead, email templates and the installable app identity are configuration — rebranding needs no code changes.' },
    { q: 'Does it replace our accounting system?', a: 'No. It runs the stock side of the business. Every movement carries its cost, and valuation reports, exports and the API give your accounting team what they need.' },
    { q: 'Where is our data hosted?', a: 'On your own server or on managed hosting of your choice. It runs on standard PHP and MySQL hosting, and every deployment belongs to one company.' },
]);
</script>

<template>
    <div>
        <Head title="Inventory control for every branch, lot and warehouse" />

        <!-- Hero -->
        <section class="mk-hero relative isolate overflow-hidden text-white">
            <div class="mk-grid pointer-events-none absolute inset-0 -z-10" aria-hidden="true" />
            <div class="mk-container grid items-center gap-14 pt-16 pb-24 sm:pt-24 lg:grid-cols-[1.05fr_1fr] lg:pb-32">
                <div>
                    <p class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/5 px-3 py-1 text-xs font-semibold tracking-wide text-white/90 motion-safe:animate-rise">
                        <span class="size-1.5 rounded-full bg-product-teal" />Multi-branch inventory platform
                    </p>
                    <h1 class="mt-6 text-4xl font-bold tracking-tight text-balance sm:text-5xl lg:text-6xl motion-safe:animate-rise">
                        Know what you have, where it is, and <span class="text-product-teal">when it expires.</span>
                    </h1>
                    <p class="mt-6 max-w-xl text-lg leading-relaxed text-white/80 text-pretty motion-safe:animate-rise">
                        {{ name }} runs purchasing, receiving, transfers, sales fulfilment and stocktaking for every branch and warehouse of your
                        company from one unified ledger — with lot, expiry and approval control built in.
                    </p>
                    <div class="mt-9 flex flex-wrap gap-3 motion-safe:animate-rise">
                        <Link href="/contact" class="btn btn-product min-h-12 px-6 text-base">Book a demo<AppIcon name="arrow-right" :size="18" /></Link>
                        <Link href="/features" class="btn btn-on-dark min-h-12 px-6 text-base">Explore features</Link>
                    </div>
                    <ul class="mt-10 flex flex-wrap gap-x-6 gap-y-2 text-sm text-white/70">
                        <li v-for="t in ['Lot & expiry tracking', 'Verified branch transfers', 'Phone, tablet & desktop']" :key="t" class="flex items-center gap-2">
                            <AppIcon name="check" :size="16" class="text-product-teal" />{{ t }}
                        </li>
                    </ul>
                </div>
                <ProductPreview class="motion-safe:animate-rise" />
            </div>
        </section>

        <!-- Facts -->
        <section class="border-b border-line bg-surface">
            <dl class="mk-container grid grid-cols-2 gap-px py-2 lg:grid-cols-4">
                <div v-for="f in facts" :key="f.value" class="px-3 py-6 text-center sm:px-4 sm:py-8">
                    <dt class="text-xl font-bold tracking-tight text-product-ink sm:text-3xl">{{ f.value }}</dt>
                    <dd class="mt-1 text-sm text-ink-muted">{{ f.text }}</dd>
                </div>
            </dl>
        </section>

        <!-- Pillars -->
        <section class="mk-container py-20 sm:py-28">
            <SectionIntro
                eyebrow="Why companies switch"
                :title="`Spreadsheets drift. Branch systems disagree. ${name} keeps one version of the truth.`"
                lead="Stock is money on shelves. When every branch keeps its own numbers, nobody knows what is really there, what is about to expire, or where it went."
            />
            <div class="mt-14 grid gap-6 md:grid-cols-3">
                <article v-for="p in pillars" :key="p.title" class="group rounded-2xl border border-line bg-surface p-7 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                    <span class="grid size-11 place-items-center rounded-xl bg-product-accent/10 text-product-accent"><AppIcon :name="p.icon" :size="22" /></span>
                    <h3 class="mt-5 text-lg font-semibold">{{ p.title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ p.text }}</p>
                </article>
            </div>
        </section>

        <!-- Flow -->
        <section class="bg-surface-muted py-20 sm:py-28">
            <div class="mk-container">
                <SectionIntro center eyebrow="End to end" title="From supplier to customer, one connected flow" lead="Each step hands over to the next with the exact lots, quantities and approvals — no re-keying between branches." />
                <div class="relative mt-16">
                <div class="pointer-events-none absolute top-6 right-[8%] left-[8%] hidden h-px bg-linear-to-r from-transparent via-product-accent/40 to-transparent lg:block" aria-hidden="true" />
                <ol class="relative grid gap-6 sm:grid-cols-2 lg:grid-cols-6">
                    <li v-for="(s, i) in flow" :key="s.title" class="relative flex gap-4 lg:flex-col lg:items-center lg:text-center">
                        <span class="relative grid size-12 shrink-0 place-items-center rounded-2xl bg-surface text-product-accent shadow-sm ring-1 ring-line">
                            <AppIcon :name="s.icon" :size="22" />
                            <span class="absolute -top-2 -right-2 grid size-5 place-items-center rounded-full bg-product-ink text-[0.65rem] font-bold text-white">{{ i + 1 }}</span>
                        </span>
                        <div>
                            <h3 class="font-semibold">{{ s.title }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-ink-muted">{{ s.text }}</p>
                        </div>
                    </li>
                </ol>
                </div>
            </div>
        </section>

        <!-- Lots -->
        <section class="mk-container grid items-center gap-14 py-20 sm:py-28 lg:grid-cols-2">
            <div>
                <SectionIntro eyebrow="Batch traceability" title="Every lot, followed from the receiving dock to the customer" />
                <p class="mt-4 text-lg leading-relaxed text-ink-muted">
                    Lot numbers, manufacture and expiry dates are captured before stock becomes available. From then on, every receipt, QC decision,
                    storage position, transfer, sale, return and adjustment is reproducible — forward and backward, across every branch.
                </p>
                <CheckList class="mt-8" :items="['Earliest-expiry-first picking with expiry warnings', 'Recalls lock the affected lots in every branch at once', 'Quarantine and QC release before stock can be used', 'Serial numbers for items that need them']" />
            </div>
            <div class="rounded-3xl border border-line bg-surface-muted p-6 sm:p-8">
                <div class="rounded-2xl border border-line bg-surface p-6 shadow-sm" role="img" aria-label="Example trace of lot AMX-2604A from receipt to sale">
                    <div class="flex items-center justify-between gap-3" aria-hidden="true">
                        <div>
                            <p class="text-xs font-semibold tracking-wide text-ink-muted uppercase">Lot trace</p>
                            <p class="mt-1 font-mono text-lg font-bold">AMX-2604A</p>
                        </div>
                        <span class="rounded-full bg-success/10 px-3 py-1 text-xs font-semibold text-success">Exp 03/2028</span>
                    </div>
                    <ol class="mt-6 space-y-5 border-l-2 border-product-accent/30 pl-5" aria-hidden="true">
                        <li v-for="t in lotTimeline" :key="t.what" class="relative">
                            <span class="absolute top-1 -left-[1.6rem] size-3 rounded-full border-2 border-surface bg-product-accent" />
                            <p class="text-sm font-semibold">{{ t.what }} <span class="ml-1 font-normal text-ink-muted">· {{ t.when }}</span></p>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ t.detail }}</p>
                        </li>
                    </ol>
                </div>
            </div>
        </section>

        <!-- Transfers -->
        <section class="bg-surface-muted">
            <div class="mk-container grid items-center gap-14 py-20 sm:py-28 lg:grid-cols-2">
                <div class="order-2 lg:order-1">
                    <div class="grid gap-4 sm:grid-cols-3" role="img" aria-label="Transfer steps: dispatched with exact lots, locked in transit, verified on receipt">
                        <div v-for="(s, i) in [{ icon: 'package', title: 'Dispatched', text: 'Exact lots and serials recorded' }, { icon: 'lock', title: 'In transit', text: 'Lots locked: no issue, sale or adjustment' }, { icon: 'check', title: 'Received', text: 'Same lots verified before stock increases' }]" :key="s.title"
                             :class="['rounded-2xl border bg-surface p-5 shadow-sm', i === 1 ? 'border-product-accent ring-2 ring-product-accent/20' : 'border-line']" aria-hidden="true">
                            <span class="grid size-10 place-items-center rounded-xl bg-product-accent/10 text-product-accent"><AppIcon :name="s.icon" :size="20" /></span>
                            <p class="mt-4 font-semibold">{{ s.title }}</p>
                            <p class="mt-1 text-sm leading-relaxed text-ink-muted">{{ s.text }}</p>
                        </div>
                    </div>
                </div>
                <div class="order-1 lg:order-2">
                    <SectionIntro eyebrow="Multi-branch distribution" title="Transfers verified at both ends" />
                    <p class="mt-4 text-lg leading-relaxed text-ink-muted">
                        HQ turns an allocation plan into approved transfers. The sending branch records exactly which lots leave; while they travel those lots
                        cannot be sold, issued or adjusted; the receiving branch verifies the same lots before its stock goes up. Differences are recorded,
                        approved and traceable — never silently absorbed.
                    </p>
                    <Link href="/features#distribution" class="mt-8 inline-flex items-center gap-2 text-sm font-semibold text-product-accent hover:underline">
                        How distribution works<AppIcon name="arrow-right" :size="16" />
                    </Link>
                </div>
            </div>
        </section>

        <!-- Reporting -->
        <section class="mk-container grid items-center gap-14 py-20 sm:py-28 lg:grid-cols-2">
            <div>
                <SectionIntro eyebrow="Reporting" title="Answers in minutes, without waiting for IT" />
                <p class="mt-4 text-lg leading-relaxed text-ink-muted">
                    Build your own reports from a catalogue of datasets — stock positions, movements, lots and expiry, transfers, valuation, sales and
                    more. Filter, group and total, then view on screen or export. Everyone sees only the data their role allows.
                </p>
                <CheckList class="mt-8" :items="['Web view with totals, grouping and drill-down', 'PDF with your letterhead, Excel and CSV exports', 'Cost data hidden from roles that may not see it', 'Valuation by item and warehouse at any date']" />
            </div>
            <div class="rounded-3xl border border-line bg-surface-muted p-6 sm:p-8">
                <div class="rounded-2xl border border-line bg-surface p-6 shadow-sm" role="img" aria-label="Example report: stock value by branch">
                    <div class="flex items-center justify-between" aria-hidden="true">
                        <p class="font-semibold">Stock value by branch</p>
                        <div class="flex gap-1.5">
                            <span v-for="f in ['PDF', 'XLSX', 'CSV']" :key="f" class="rounded-md border border-line px-2 py-0.5 text-[0.65rem] font-semibold text-ink-muted">{{ f }}</span>
                        </div>
                    </div>
                    <div class="mt-6 space-y-3" aria-hidden="true">
                        <div v-for="b in [{ n: 'Headquarters', w: 'w-[92%]' }, { n: 'Regional depot', w: 'w-[71%]' }, { n: 'Store 1', w: 'w-[46%]' }, { n: 'Store 2', w: 'w-[38%]' }, { n: 'Fulfilment hub', w: 'w-[27%]' }]" :key="b.n" class="grid grid-cols-[7rem_1fr] items-center gap-3 text-xs">
                            <span class="truncate text-ink-muted">{{ b.n }}</span>
                            <span class="h-3 overflow-hidden rounded-full bg-surface-muted"><span :class="['block h-full rounded-full bg-linear-to-r from-product-ink to-product-accent', b.w]" /></span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Segments -->
        <section class="bg-surface-muted py-20 sm:py-28">
            <div class="mk-container">
                <SectionIntro center eyebrow="Solutions" title="Built for companies whose stock moves between places" />
                <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <Link v-for="s in segments" :key="s.id" :href="`/solutions#${s.id}`" class="group flex flex-col rounded-2xl border border-line bg-surface p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-product-accent/40 hover:shadow-md">
                        <span class="grid size-11 place-items-center rounded-xl bg-product-ink text-white"><AppIcon :name="s.icon" :size="22" /></span>
                        <h3 class="mt-5 font-semibold">{{ s.title }}</h3>
                        <p class="mt-2 flex-1 text-sm leading-relaxed text-ink-muted">{{ s.text }}</p>
                        <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-product-accent">Learn more<AppIcon name="arrow-right" :size="15" class="transition group-hover:translate-x-0.5" /></span>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Floor + security -->
        <section class="mk-container grid gap-6 py-20 sm:py-28 lg:grid-cols-2">
            <div class="rounded-3xl border border-line bg-surface p-8 shadow-sm sm:p-10">
                <span class="grid size-11 place-items-center rounded-xl bg-product-accent/10 text-product-accent"><AppIcon name="smartphone" :size="22" /></span>
                <h2 class="mt-6 text-2xl font-bold tracking-tight">Made for the warehouse floor</h2>
                <p class="mt-3 leading-relaxed text-ink-muted">Storekeepers, pickers and drivers work on the device in their hand. Office teams get the full picture on a large screen.</p>
                <CheckList class="mt-6" :items="floor" />
            </div>
            <div class="mk-hero relative isolate overflow-hidden rounded-3xl p-8 text-white shadow-xl sm:p-10">
                <div class="mk-grid pointer-events-none absolute inset-0 -z-10" aria-hidden="true" />
                <span class="grid size-11 place-items-center rounded-xl bg-white/10 text-product-teal"><AppIcon name="shield" :size="22" /></span>
                <h2 class="mt-6 text-2xl font-bold tracking-tight">Controls your auditors will appreciate</h2>
                <p class="mt-3 leading-relaxed text-white/75">Ledger entries cannot be edited or deleted — the database itself enforces it. Every approval, override and change of access is recorded.</p>
                <CheckList dark class="mt-6" :items="['Append-only stock ledger and audit trail', 'Two-factor sign-in and re-confirmation for sensitive actions', 'Separation of duties and approval limits', 'Every override needs a reason and is reportable']" />
                <Link href="/security" class="btn btn-on-dark mt-8">How we protect your data<AppIcon name="arrow-right" :size="16" /></Link>
            </div>
        </section>

        <!-- FAQ -->
        <section class="border-t border-line bg-surface-muted py-20 sm:py-28">
            <div class="mk-container grid gap-12 lg:grid-cols-[1fr_1.6fr]">
                <SectionIntro eyebrow="Questions" title="Frequently asked" :lead="`Something else on your mind? Ask us — we are happy to walk through your situation.`" />
                <div class="divide-y divide-line rounded-2xl border border-line bg-surface">
                    <details v-for="f in faqs" :key="f.q" class="group p-6 [&_summary::-webkit-details-marker]:hidden">
                        <summary class="flex cursor-pointer list-none items-start justify-between gap-6 font-semibold">
                            {{ f.q }}
                            <AppIcon name="chevron-down" :size="18" class="mt-0.5 text-ink-muted transition group-open:rotate-180" />
                        </summary>
                        <p class="mt-3 text-sm leading-relaxed text-ink-muted">{{ f.a }}</p>
                    </details>
                </div>
            </div>
        </section>

        <CtaBand />
    </div>
</template>
