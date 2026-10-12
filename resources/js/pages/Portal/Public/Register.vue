<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import PasswordInput from '@/components/ui/PasswordInput.vue';
import { ApiError, get, post } from '@/lib/api';
import ResendVerificationForm from '@/components/portal/ResendVerificationForm.vue';

/**
 * Customer registration (CP-1.1 section 4). The visitor gives their own and their company's details;
 * staff decide which customer the account belongs to — nothing here can choose a customer, price,
 * credit term or role.
 */
const form = reactive({ full_name: '', email: '', phone: '', company_name: '', tax_id: '', city: '', address: '', message: '',
    password: '', password_confirmation: '', accept_terms: false });
const mode = ref<string>('approval');
const busy = ref(false);
const error = ref<unknown>(null);
const sent = ref<string | null>(null);
const fieldErrors = computed(() => (error.value instanceof ApiError ? error.value.fieldErrors() : {}));
const mismatch = computed(() => form.password_confirmation !== '' && form.password !== form.password_confirmation);

onMounted(async () => {
    mode.value = (await get('/api/v1/portal/public/info').catch(() => ({ data: { registration_mode: 'approval' } }))).data.registration_mode;
});

async function submit() {
    if (mismatch.value) {
        return;
    }
    busy.value = true;
    error.value = null;
    try {
        const r = await post('/api/v1/portal/public/register', {
            full_name: form.full_name, email: form.email, phone: form.phone || null, company_name: form.company_name, tax_id: form.tax_id || null,
            address: form.city || form.address ? { line1: form.address || null, city: form.city || null } : null, message: form.message || null,
            password: form.password, password_confirmation: form.password_confirmation, accept_terms: form.accept_terms,
        });
        sent.value = r.data.message;
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Sign up" />
    <div class="mx-auto max-w-2xl px-4 py-10">
        <h1 class="text-2xl font-bold">Open a customer account</h1>

        <div v-if="sent" class="card mt-6 space-y-3" role="status">
            <h2 class="text-lg font-semibold">Check your e-mail</h2>
            <p>{{ sent }}</p>
            <p class="text-sm text-ink-muted">Next you will set up two-step verification. Our team then reviews your registration and e-mails you when your account is ready.</p>
            <details class="text-sm">
                <summary class="cursor-pointer text-ink-muted underline">No e-mail after a few minutes?</summary>
                <div class="mt-3"><ResendVerificationForm :email="form.email" compact /></div>
            </details>
        </div>

        <div v-else-if="mode !== 'approval'" class="card mt-6">
            <p v-if="mode === 'invitation_only'">New accounts are opened by invitation. Contact us and we will send an invitation to your e-mail address.</p>
            <p v-else>New registrations are closed at the moment.</p>
            <Link href="/portal" class="btn btn-secondary mt-4">Back</Link>
        </div>

        <form v-else class="mt-6 space-y-6" novalidate @submit.prevent="submit">
            <ErrorSummary :error="error" />
            <fieldset class="card space-y-4">
                <legend class="px-1 font-semibold">About you</legend>
                <div>
                    <label for="r-name" class="label">Full name <span class="text-danger">*</span></label>
                    <input id="r-name" v-model="form.full_name" class="input" autocomplete="name" required :class="{ 'input-invalid': fieldErrors.full_name }">
                    <p v-if="fieldErrors.full_name" class="error-text">{{ fieldErrors.full_name }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="r-email" class="label">Work e-mail <span class="text-danger">*</span></label>
                        <input id="r-email" v-model="form.email" type="email" class="input" autocomplete="email" required :class="{ 'input-invalid': fieldErrors.email }">
                        <p v-if="fieldErrors.email" class="error-text">{{ fieldErrors.email }}</p>
                    </div>
                    <div>
                        <label for="r-phone" class="label">Phone</label>
                        <input id="r-phone" v-model="form.phone" type="tel" class="input" autocomplete="tel" :class="{ 'input-invalid': fieldErrors.phone }">
                        <p v-if="fieldErrors.phone" class="error-text">{{ fieldErrors.phone }}</p>
                    </div>
                </div>
            </fieldset>

            <fieldset class="card space-y-4">
                <legend class="px-1 font-semibold">Your company</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="r-company" class="label">Company name <span class="text-danger">*</span></label>
                        <input id="r-company" v-model="form.company_name" class="input" autocomplete="organization" required :class="{ 'input-invalid': fieldErrors.company_name }">
                        <p v-if="fieldErrors.company_name" class="error-text">{{ fieldErrors.company_name }}</p>
                    </div>
                    <div>
                        <label for="r-tin" class="label">Tax ID (TIN)</label>
                        <input id="r-tin" v-model="form.tax_id" class="input">
                    </div>
                    <div>
                        <label for="r-city" class="label">City</label>
                        <input id="r-city" v-model="form.city" class="input" autocomplete="address-level2">
                    </div>
                    <div>
                        <label for="r-address" class="label">Address</label>
                        <input id="r-address" v-model="form.address" class="input" autocomplete="street-address">
                    </div>
                </div>
                <div>
                    <label for="r-message" class="label">Anything we should know?</label>
                    <textarea id="r-message" v-model="form.message" class="input min-h-20" placeholder="For example: we already buy from you as …" />
                </div>
            </fieldset>

            <fieldset class="card space-y-4">
                <legend class="px-1 font-semibold">Password</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="r-password" class="label">Password <span class="text-danger">*</span></label>
                        <PasswordInput id="r-password" v-model="form.password" generate autocomplete="new-password" required :class="{ 'input-invalid': fieldErrors.password }"
                                       @generated="form.password_confirmation = $event" />
                        <p v-if="fieldErrors.password" class="error-text">{{ fieldErrors.password }}</p>
                        <p v-else class="help">At least 12 characters, mixing letters, digits and symbols.</p>
                    </div>
                    <div>
                        <label for="r-password2" class="label">Repeat password <span class="text-danger">*</span></label>
                        <PasswordInput id="r-password2" v-model="form.password_confirmation" autocomplete="new-password" required
                                       :class="{ 'input-invalid': mismatch || fieldErrors.password_confirmation }" />
                        <p v-if="mismatch" class="error-text">The passwords do not match.</p>
                        <p v-else-if="fieldErrors.password_confirmation" class="error-text">{{ fieldErrors.password_confirmation }}</p>
                    </div>
                </div>
            </fieldset>

            <label class="flex min-h-11 items-start gap-3 text-sm">
                <input v-model="form.accept_terms" type="checkbox" class="mt-1 size-5 accent-brand" required>
                <span>I confirm the details are correct and I am allowed to open an account for this company.</span>
            </label>
            <p v-if="fieldErrors.accept_terms" class="error-text">{{ fieldErrors.accept_terms }}</p>
            <div class="flex flex-wrap items-center gap-3">
                <UiButton variant="primary" type="submit" :loading="busy" :disabled="!form.accept_terms || mismatch">Create account</UiButton>
                <span class="text-sm text-ink-muted">Already registered? <Link href="/portal/login" class="underline">Sign in</Link></span>
            </div>
        </form>
    </div>
</template>
