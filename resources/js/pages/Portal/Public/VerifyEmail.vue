<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import ResendVerificationForm from '@/components/portal/ResendVerificationForm.vue';
import { post } from '@/lib/api';

/** Landing page of the e-mail confirmation link: proves the address, then continues to two-step verification. */
const error = ref<unknown>(null);
const busy = ref(true);

onMounted(async () => {
    const params = new URLSearchParams(window.location.search);
    try {
        await post('/api/v1/portal/public/verify-email', { registration: params.get('registration') ?? '', token: params.get('token') ?? '' });
        router.visit('/portal/security-setup');
    } catch (e) {
        error.value = e;
        busy.value = false;
    }
});
</script>

<template>
    <Head title="Confirm your e-mail" />
    <div class="mx-auto max-w-lg px-4 py-16">
        <div class="card space-y-4">
            <h1 class="text-xl font-bold">Confirming your e-mail address…</h1>
            <p v-if="busy" class="text-sm text-ink-muted" aria-live="polite">One moment.</p>
            <template v-else>
                <ErrorSummary :error="error" />
                <h2 class="font-semibold">Get a new link</h2>
                <ResendVerificationForm />
                <p class="text-sm text-ink-muted">Never registered? <Link href="/portal/register" class="underline">Sign up</Link></p>
            </template>
        </div>
    </div>
</template>
