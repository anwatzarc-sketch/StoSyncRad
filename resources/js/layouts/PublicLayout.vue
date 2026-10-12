<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ToastHost from '@/components/ui/ToastHost.vue';
import ProductFooter from '@/components/ProductFooter.vue';
import ProductWordmark from '@/components/ProductWordmark.vue';

/** Public Customer Portal pages (landing, registration, sign-in): no account, no staff navigation. */
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-surface text-ink">
        <header class="border-b border-line bg-surface">
            <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4">
                <Link href="/portal" class="min-w-0"><ProductWordmark size="sm" show-company /></Link>
                <nav class="ml-auto flex items-center gap-2" aria-label="Account">
                    <Link href="/portal/login" class="btn btn-ghost">Sign in</Link>
                    <Link href="/portal/register" class="btn btn-primary hidden sm:inline-flex">Sign up</Link>
                </nav>
            </div>
        </header>
        <main id="main" class="flex-1">
            <slot />
        </main>
        <div class="mx-auto w-full max-w-6xl px-4 pb-8">
            <ProductFooter class="border-t border-line pt-4" />
        </div>
        <ToastHost />
    </div>
</template>
