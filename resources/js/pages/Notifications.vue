<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, post, put } from '@/lib/api';
import { relativeTime } from '@/lib/format';
import { humanize } from '@/lib/meta';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';
import type { NextAction } from '@/types/shared';

/**
 * My notifications, and how I want to receive each kind (in the app, by e-mail, or muted). Next
 * action: open the newest unread notification that leads somewhere.
 */
interface Preference { event_type: string; channels: string[]; is_muted: boolean }

/** Sent to customer portal members, never to staff. */
const CUSTOMER_EVENTS = ['quotation_update', 'order_update', 'invoice_issued', 'payment_update'];
const CHANNELS = [{ value: 'in_app', label: 'In the app' }, { value: 'email', label: 'E-mail' }];

const { can } = useShared();
const items = ref<Record<string, any>[]>([]);
const status = ref<'unread' | ''>('unread');
const loading = ref(true);
const error = ref<unknown>(null);
const preferences = ref<Preference[]>([]);
const saving = ref<string | null>(null);

const nextActions = computed<NextAction[]>(() => {
    const unread = items.value.find((n) => n.status === 'unread' && n.link);
    if (unread) {
        return [{ label: `Open: ${unread.title}`, icon: 'arrow-right', href: unread.link, onClick: () => void mark([unread.id]),
            hint: `${items.value.filter((n) => n.status === 'unread').length} unread. Each one points at the work it is about.` }];
    }

    return loading.value ? [] : [{ label: 'Back to my work', icon: 'home', href: '/', hint: 'Nothing new. Your work is waiting on Home.' }];
});

async function load() {
    loading.value = true;
    try {
        items.value = (await get('/api/v1/notifications', { status: status.value || undefined, per_page: 50 })).data;
    } catch (e) {
        error.value = e;
    } finally {
        loading.value = false;
    }
}

async function mark(ids?: string[]) {
    await post('/api/v1/notifications/mark', { ids, status: 'read' });
    await load();
}

async function loadPreferences() {
    if (!can('messaging.notification.manage_own_preferences')) {
        return;
    }
    try {
        preferences.value = ((await get<{ data: Preference[] }>('/api/v1/notifications/preferences')).data).filter((p) => !CUSTOMER_EVENTS.includes(p.event_type));
    } catch {
        preferences.value = [];
    }
}

async function savePreference(p: Preference) {
    saving.value = p.event_type;
    error.value = null;
    try {
        await put('/api/v1/notifications/preferences', { event_type: p.event_type, channels: p.channels, is_muted: p.is_muted });
        notify(`${humanize(p.event_type)}: saved.`);
    } catch (e) {
        error.value = e;
    } finally {
        saving.value = null;
    }
}

function toggle(p: Preference, channel: string) {
    p.channels = p.channels.includes(channel) ? p.channels.filter((c) => c !== channel) : [...p.channels, channel];
    void savePreference(p);
}

onMounted(() => Promise.all([load(), loadPreferences()]));
</script>

<template>
    <PageHeader title="Notifications" :next="nextActions">
        <template #actions>
            <select v-model="status" class="input w-auto" aria-label="Show" @change="load">
                <option value="unread">Unread</option>
                <option value="">All</option>
            </select>
            <UiButton :disabled="items.length === 0" @click="mark()">Mark all read</UiButton>
        </template>
    </PageHeader>
    <ErrorSummary :error="error" />
    <div class="card">
        <p v-if="!loading && items.length === 0" class="py-8 text-center text-sm text-ink-muted">No notifications.</p>
        <ul class="divide-y divide-line">
            <li v-for="n in items" :key="n.id" class="flex items-start gap-3 py-3">
                <span :class="['mt-2 size-2 shrink-0 rounded-full', n.status === 'unread' ? 'bg-accent' : 'bg-transparent']" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <component :is="n.link ? Link : 'p'" :href="n.link ?? undefined" class="font-medium" :class="n.link ? 'text-brand hover:underline' : ''" @click="n.status === 'unread' && mark([n.id])">
                        {{ n.title }}
                    </component>
                    <p v-if="n.body" class="text-sm text-ink-muted">{{ n.body }}</p>
                    <p class="text-xs text-ink-muted">{{ relativeTime(n.created_at) }}</p>
                </div>
                <button v-if="n.status === 'unread'" type="button" class="btn btn-ghost min-h-9 px-2 text-sm" @click="mark([n.id])">Mark read</button>
            </li>
        </ul>
    </div>

    <section v-if="preferences.length" class="card mt-6" aria-labelledby="prefs-title">
        <h2 id="prefs-title" class="font-semibold">How I am notified</h2>
        <p class="mb-3 text-sm text-ink-muted">Choose where each kind of notification reaches you. Muted kinds are not sent at all.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-ink-muted">
                        <th scope="col" class="py-2 pr-4 font-medium">Notification</th>
                        <th v-for="c in CHANNELS" :key="c.value" scope="col" class="px-3 py-2 text-center font-medium">{{ c.label }}</th>
                        <th scope="col" class="px-3 py-2 text-center font-medium">Muted</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="p in preferences" :key="p.event_type" :class="p.is_muted ? 'opacity-60' : ''">
                        <th scope="row" class="py-2 pr-4 text-left font-normal">{{ humanize(p.event_type) }}</th>
                        <td v-for="c in CHANNELS" :key="c.value" class="px-3 py-2 text-center">
                            <input
                                type="checkbox"
                                class="size-5 accent-brand"
                                :checked="p.channels.includes(c.value)"
                                :disabled="p.is_muted || saving === p.event_type"
                                :aria-label="`${humanize(p.event_type)} — ${c.label}`"
                                @change="toggle(p, c.value)"
                            >
                        </td>
                        <td class="px-3 py-2 text-center">
                            <input
                                v-model="p.is_muted"
                                type="checkbox"
                                class="size-5 accent-brand"
                                :disabled="saving === p.event_type"
                                :aria-label="`Mute ${humanize(p.event_type)}`"
                                @change="savePreference(p)"
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
