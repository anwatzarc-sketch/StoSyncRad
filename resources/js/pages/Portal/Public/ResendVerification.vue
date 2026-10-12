<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ResendVerificationForm from '@/components/portal/ResendVerificationForm.vue';

/** Public page: ask for a new confirmation link for a customer portal registration. */
const email = new URLSearchParams(window.location.search).get('email') ?? '';
</script>

<template>
    <Head title="Resend confirmation e-mail" />
    <div class="mx-auto max-w-lg px-4 py-16">
        <div class="card space-y-4">
            <h1 class="text-xl font-bold">Didn't get the confirmation e-mail?</h1>
            <p class="text-sm text-ink-muted">
                Enter the e-mail address you registered with and we'll send a new link. It replaces any earlier link, and you can use it to
                finish setting up two-step verification too.
            </p>
            <ResendVerificationForm :email="email" />
            <p class="text-sm text-ink-muted">Already set up? <Link href="/portal/login" class="underline">Sign in</Link></p>
        </div>
    </div>
</template>
