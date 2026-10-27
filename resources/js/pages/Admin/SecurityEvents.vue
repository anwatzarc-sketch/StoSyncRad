<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import EnumSelect from '@/components/ui/EnumSelect.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { enumLabel, loadEnums } from '@/lib/meta';
import { formatDateTime } from '@/lib/format';
import { useShared } from '@/composables/useShared';
import type { NextAction } from '@/types/shared';

/** Sign-ins, lockouts, MFA changes, token use — the identity security trail (Chapter 14). Next action: act on the account. */
const { can } = useShared();
const type = ref<string | null>(null);
const events = ref<Record<string, any>[]>([]);
const cursor = ref<string | null>(null);
const error = ref<unknown>(null);

const nextActions = computed<NextAction[]>(() => {
    const flagged = events.value.find((e) => e.account_id && /(lock|fail|suspicious|reuse)/.test(String(e.event_type)));

    return [
        ...(flagged && can('identity.account.read') ? [{ label: 'Open the account', icon: 'user', href: `/r/accounts/${flagged.account_id}`,
            hint: `Latest worrying event: ${flagged.event_type_label ?? enumLabel('security_event_type', flagged.event_type)}. Lock it, reset MFA or end its sessions there.` }] : []),
        ...(can('audit.event.read') ? [{ label: 'Audit log', icon: 'shield', href: '/admin/audit' }] : []),
    ];
});

async function load(append = false) {
    try {
        const r = await get('/api/v1/admin/security-events', { event_type: type.value, cursor: append ? cursor.value : undefined });
        events.value = append ? [...events.value, ...r.data] : r.data;
        cursor.value = r.meta?.next_cursor ?? null;
    } catch (e) {
        error.value = e;
    }
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    await load();
});
</script>

<template>
    <PageHeader title="Security events" :next="nextActions" />
    <div class="card mb-4 max-w-sm">
        <label for="se-type" class="label">Event</label>
        <EnumSelect id="se-type" v-model="type" enum-code="security_event_type" nullable placeholder="All events" @update:model-value="load()" />
    </div>
    <ErrorSummary :error="error" />
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th scope="col">When</th><th scope="col">Event</th><th scope="col">Account</th><th scope="col">IP</th><th scope="col">Details</th></tr></thead>
            <tbody>
                <tr v-for="e in events" :key="e.id">
                    <td class="whitespace-nowrap">{{ formatDateTime(e.occurred_at) }}</td>
                    <td>{{ e.event_type_label ?? enumLabel('security_event_type', e.event_type) }}</td>
                    <td class="font-mono text-xs">{{ e.account_id }}</td>
                    <td class="font-mono text-xs">{{ e.ip_address }}</td>
                    <td class="text-xs text-ink-muted">{{ e.context ? JSON.stringify(e.context) : '' }}</td>
                </tr>
            </tbody>
        </table>
        <p v-if="events.length === 0" class="py-8 text-center text-sm text-ink-muted">No events.</p>
        <div v-if="cursor" class="mt-3 text-center"><UiButton @click="load(true)">Load more</UiButton></div>
    </div>
</template>
