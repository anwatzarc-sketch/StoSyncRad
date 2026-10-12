<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { ApiError, api } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { attemptKey, completeAttempt } from '@/lib/idempotency';

/**
 * "Confirm payment" (CP-1.1 section 5.6): the customer reports a payment with their bank slip or
 * transfer screenshot. It is evidence for our finance team — nothing is marked paid until they verify
 * that the money arrived.
 */
const props = defineProps<{ invoiceId?: string | null; orderId?: string | null; currency: string; outstanding: string }>();
const emit = defineEmits<{ submitted: [id: string] }>();

const form = reactive({ amount: props.outstanding, paid_on: new Date().toISOString().slice(0, 10), method: 'bank_transfer', transaction_reference: '',
    payer_name: '', bank_name: '', note: '' });
const files = ref<File[]>([]);
const busy = ref(false);
const error = ref<unknown>(null);
const fieldErrors = computed(() => (error.value instanceof ApiError ? error.value.fieldErrors() : {}));
const scope = computed(() => `portal-payment-${props.invoiceId ?? props.orderId}`);
const ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';

function pick(event: Event) {
    files.value = Array.from((event.target as HTMLInputElement).files ?? []).slice(0, 5);
}

async function submit() {
    busy.value = true;
    error.value = null;
    try {
        const data = new FormData();
        if (props.invoiceId) {
            data.append('invoice_id', props.invoiceId);
        } else if (props.orderId) {
            data.append('sales_order_id', props.orderId);
        }
        for (const [key, value] of Object.entries(form)) {
            if (value !== '') {
                data.append(key, value);
            }
        }
        files.value.forEach((f) => data.append('evidence[]', f));
        const r = await api('/api/v1/portal/payments', { method: 'POST', form: data, idempotencyKey: attemptKey(scope.value) });
        completeAttempt(scope.value);
        emit('submitted', r.data.id);
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <p class="text-sm text-ink-muted">
            Outstanding: <strong class="text-ink">{{ formatMoney(outstanding, currency) }}</strong>. Your confirmation is checked by our finance team against our bank
            account; the balance changes once they verify it.
        </p>
        <ErrorSummary :error="error" />
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="p-amount" class="label">Amount paid ({{ currency }}) <span class="text-danger">*</span></label>
                <input id="p-amount" v-model="form.amount" class="input" inputmode="decimal" required :class="{ 'input-invalid': fieldErrors.amount }">
                <p v-if="fieldErrors.amount" class="error-text">{{ fieldErrors.amount }}</p>
            </div>
            <div>
                <label for="p-date" class="label">Paid on <span class="text-danger">*</span></label>
                <input id="p-date" v-model="form.paid_on" type="date" class="input" required :max="new Date().toISOString().slice(0, 10)">
            </div>
            <div>
                <label for="p-method" class="label">How you paid <span class="text-danger">*</span></label>
                <select id="p-method" v-model="form.method" class="input">
                    <option value="bank_transfer">Bank transfer</option>
                    <option value="mobile_banking">Mobile banking / wallet</option>
                    <option value="cash_deposit">Cash deposit at the bank</option>
                    <option value="cheque">Cheque</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label for="p-ref" class="label">Transaction reference <span class="text-danger">*</span></label>
                <input id="p-ref" v-model="form.transaction_reference" class="input" required maxlength="120" :class="{ 'input-invalid': fieldErrors.transaction_reference }">
                <p v-if="fieldErrors.transaction_reference" class="error-text">{{ fieldErrors.transaction_reference }}</p>
                <p v-else class="help">As shown on the slip or in the banking app.</p>
            </div>
            <div>
                <label for="p-payer" class="label">Paid from the account of</label>
                <input id="p-payer" v-model="form.payer_name" class="input" maxlength="200">
            </div>
            <div>
                <label for="p-bank" class="label">Bank or wallet</label>
                <input id="p-bank" v-model="form.bank_name" class="input" maxlength="120">
            </div>
        </div>
        <div>
            <label for="p-files" class="label">Bank slip, screenshot or PDF <span class="text-danger">*</span></label>
            <input id="p-files" type="file" multiple :accept="ACCEPT" class="block w-full text-sm" capture="environment" @change="pick">
            <p class="help">JPG, PNG, WebP or PDF, up to 5 files. Only you and our staff can open them.</p>
            <p v-for="(message, key) in fieldErrors" v-show="String(key).startsWith('evidence')" :key="key" class="error-text">{{ message }}</p>
            <ul v-if="files.length" class="mt-2 text-sm text-ink-muted"><li v-for="f in files" :key="f.name">{{ f.name }} · {{ Math.ceil(f.size / 1024) }} KB</li></ul>
        </div>
        <div>
            <label for="p-note" class="label">Note</label>
            <textarea id="p-note" v-model="form.note" class="input min-h-16" />
        </div>
        <UiButton variant="primary" type="submit" :loading="busy" :disabled="!files.length || !form.transaction_reference">Send payment confirmation</UiButton>
    </form>
</template>
