<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import CheckList from '@/components/marketing/CheckList.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import PageHero from '@/components/marketing/PageHero.vue';
import SectionIntro from '@/components/marketing/SectionIntro.vue';
import { useShared } from '@/composables/useShared';

/** Product website: how a company goes live. */
const { brand } = useShared();
const name = computed(() => brand.value.product.name);

const steps = [
    { icon: 'users', title: 'Discover', text: 'We map your branches, warehouses, item ranges, approval habits and the documents you print today.' },
    { icon: 'sliders', title: 'Configure', text: 'Company profile, locale and currency, numbering, stock and approval policies, branches and warehouses — through the guided setup wizard, or in one go from a configuration bundle.' },
    { icon: 'palette', title: 'Brand', text: 'Your company name, colours, logo, letterhead and email templates. Rebranding is configuration — nothing is rebuilt.' },
    { icon: 'upload', title: 'Import', text: 'Items, categories, units, barcodes, locations, reorder rules, suppliers, customers and opening balances from spreadsheet templates. Everything is checked as a draft first and posted only after review.' },
    { icon: 'users', title: 'Train', text: 'Role-based sessions: storekeepers on task screens, supervisors on approvals and counts, managers on reports.' },
    { icon: 'check', title: 'Go live & grow', text: 'Count your opening stock, post it, and start operating. Switch on more features and branches when you are ready.' },
];

const prepare = [
    'Your list of branches and warehouses (and the bins you use)',
    'Item list with codes, units and categories — your existing spreadsheet is fine',
    'Suppliers and customers you trade with',
    'A physical count at cut-off, by lot and expiry where it applies',
    'Who approves what, and up to which amount',
    'Your logo and the documents you print today',
];

const hosting = [
    { icon: 'building', title: 'Your own server', text: 'Run it in your server room or data centre on standard PHP and MySQL — no special infrastructure.' },
    { icon: 'globe', title: 'Managed hosting', text: 'A cloud or hosting provider of your choice, with HTTPS, scheduled tasks and backups.' },
    { icon: 'layers', title: 'Shared hosting', text: 'Compatible with control panels such as Plesk, using the database queue and the built-in PDF renderer.' },
];
</script>

<template>
    <div>
        <Head title="Onboarding" />
        <PageHero
            eyebrow="Onboarding"
            title="Live in days — not a months-long project"
            :lead="`${name} is configured and branded, never customised in code. The work of going live is understanding your business and loading your data — and we do it with you.`"
        >
            <Link href="/contact" class="btn btn-product min-h-12 px-6 text-base">Plan your rollout<AppIcon name="arrow-right" :size="18" /></Link>
        </PageHero>

        <section class="mk-container py-20 sm:py-28">
            <SectionIntro center eyebrow="The path" title="Six steps from first conversation to first posting" />
            <ol class="mx-auto mt-14 max-w-4xl space-y-4">
                <li v-for="(s, i) in steps" :key="s.title" class="relative grid grid-cols-[3.5rem_1fr] gap-5 rounded-2xl border border-line bg-surface p-6 shadow-sm">
                    <span class="relative grid size-14 place-items-center rounded-2xl bg-product-ink text-white">
                        <AppIcon :name="s.icon" :size="24" />
                        <span class="absolute -top-2 -right-2 grid size-6 place-items-center rounded-full bg-product-accent text-xs font-bold text-white">{{ i + 1 }}</span>
                    </span>
                    <div>
                        <h3 class="text-lg font-semibold">{{ s.title }}</h3>
                        <p class="mt-1 leading-relaxed text-ink-muted">{{ s.text }}</p>
                    </div>
                </li>
            </ol>
        </section>

        <section class="bg-surface-muted py-20 sm:py-28">
            <div class="mk-container grid items-start gap-12 lg:grid-cols-2">
                <div>
                    <SectionIntro eyebrow="Before we start" title="What to prepare" lead="None of this has to be perfect. We will help you clean it up during the import — but the better your starting lists, the faster you go live." />
                </div>
                <div class="rounded-2xl border border-line bg-surface p-7 shadow-sm">
                    <CheckList :items="prepare" />
                </div>
            </div>
        </section>

        <section class="mk-container py-20 sm:py-28">
            <SectionIntro center eyebrow="Deployment" title="Run it where it suits you" lead="Every installation belongs to one company and runs the same product — upgrades never fork your system." />
            <div class="mt-14 grid gap-6 md:grid-cols-3">
                <article v-for="h in hosting" :key="h.title" class="rounded-2xl border border-line bg-surface p-7 shadow-sm">
                    <span class="grid size-11 place-items-center rounded-xl bg-product-accent/10 text-product-accent"><AppIcon :name="h.icon" :size="22" /></span>
                    <h3 class="mt-5 text-lg font-semibold">{{ h.title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ h.text }}</p>
                </article>
            </div>
        </section>

        <CtaBand title="Let's plan your go-live" text="Tell us about your branches and your stock. We will come back with a rollout plan, what we need from you, and a date." />
    </div>
</template>
