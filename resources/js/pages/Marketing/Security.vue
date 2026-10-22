<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import CheckList from '@/components/marketing/CheckList.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import PageHero from '@/components/marketing/PageHero.vue';
import SectionIntro from '@/components/marketing/SectionIntro.vue';
import { useShared } from '@/composables/useShared';

/** Product website: how the product protects stock records, access and data. */
const { brand } = useShared();
const name = computed(() => brand.value.product.name);

const layers = [
    'Browser hardening', 'Rate limits & CSRF', 'Sign-in & MFA', 'Permission, scope & approval checks', 'Business rules', 'Database constraints & triggers', 'Private storage', 'Audit trail',
];

const areas = [
    {
        icon: 'layers', title: 'A ledger that cannot be rewritten',
        items: ['Posted stock movements are append-only — no edit, no delete', 'Corrections are reversals or compensating postings, linked to the original', 'Every balance can be rebuilt from posted movements; scheduled reconciliation reports any difference', 'Postings are atomic: a document posts completely or not at all'],
    },
    {
        icon: 'key', title: 'Access by permission, not by job title',
        items: ['Every action is bound to one permission; roles are built from permissions by your administrators', 'Access is scoped to the branches and warehouses a person is assigned to', 'Separation-of-duties rules stop the same person from creating and approving', 'Approval policies and limits by document type and value'],
    },
    {
        icon: 'lock', title: 'Strong sign-in',
        items: ['Passwords hashed with Argon2id and checked against your password policy', 'Two-factor sign-in with authenticator apps, with recovery codes', 'Sensitive actions ask people to confirm it is them again (step-up)', 'Session limits, sign-out on privilege change, and throttling of failed attempts'],
    },
    {
        icon: 'history', title: 'A complete audit trail',
        items: ['Append-only audit events with who, what, when, before and after', 'Sign-ins, role changes, configuration, approvals, postings, imports, exports and report downloads', 'Every override needs a specific permission, a reason and re-confirmation — and is reportable', 'Correlation IDs connect a user action to every log line and audit record'],
    },
    {
        icon: 'shield', title: 'Data protection',
        items: ['HTTPS only, strict transport security and a nonce-based content security policy', 'Sensitive fields encrypted at rest; costs and personal data shown only to roles that may see them', 'Uploads validated and stored privately; downloads re-authorised every time', 'Report exports are private and expire automatically'],
    },
    {
        icon: 'boxes', title: 'Least-privilege database access',
        items: ['The application cannot change the database structure or rewrite the ledger', 'Reports run through a separate read-only account over curated views', 'Statement and lock time limits protect the system from runaway queries', 'Built-in health checks confirm the ledger safeguards are in place'],
    },
];

const operations = [
    'Liveness and readiness health checks for monitoring',
    'Daily backups and regular restore drills in the operations runbook',
    'Structured logs without secrets or personal payloads',
    'Upgrades that never fork your installation',
];
</script>

<template>
    <div>
        <Head title="Security and control" />
        <PageHero
            eyebrow="Security & control"
            title="Built so the numbers can be trusted"
            :lead="`Stock records are financial records. ${name} protects them at every layer — from the browser to the database — so no single mistake, shortcut or bad actor can quietly change what you own.`"
        />

        <section class="border-b border-line bg-surface-muted">
            <div class="mk-container py-12">
                <p class="text-center text-sm font-semibold tracking-wide text-ink-muted uppercase">Defence in depth — every request passes every layer</p>
                <ol class="mt-6 flex flex-wrap justify-center gap-2">
                    <li v-for="(l, i) in layers" :key="l" class="flex items-center gap-2">
                        <span class="rounded-full border border-line bg-surface px-3 py-1.5 text-sm font-medium shadow-sm">{{ l }}</span>
                        <AppIcon v-if="i < layers.length - 1" name="chevron-right" :size="14" class="text-ink-muted" />
                    </li>
                </ol>
            </div>
        </section>

        <section class="mk-container py-20 sm:py-24">
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <article v-for="a in areas" :key="a.title" class="rounded-2xl border border-line bg-surface p-7 shadow-sm">
                    <span class="grid size-11 place-items-center rounded-xl bg-product-ink text-white"><AppIcon :name="a.icon" :size="22" /></span>
                    <h2 class="mt-5 text-lg font-semibold">{{ a.title }}</h2>
                    <CheckList class="mt-4" :items="a.items" />
                </article>
            </div>
        </section>

        <section class="bg-surface-muted py-20 sm:py-24">
            <div class="mk-container grid items-start gap-12 lg:grid-cols-2">
                <SectionIntro
                    eyebrow="Operations"
                    title="Security is also how the system is run"
                    lead="Your installation belongs to your company alone. We help your team set it up the right way and keep it that way."
                />
                <div class="rounded-2xl border border-line bg-surface p-7 shadow-sm">
                    <CheckList :items="operations" />
                    <p class="mt-6 border-t border-line pt-5 text-sm leading-relaxed text-ink-muted">
                        Hosting, network security, backups and access to the server are shared responsibilities — we document what the platform does and what your operators should do.
                    </p>
                </div>
            </div>
        </section>

        <CtaBand title="Walk through the controls with us" text="We will show how postings, approvals, overrides and the audit trail work on a real transfer — and answer your auditor's questions." />
    </div>
</template>
