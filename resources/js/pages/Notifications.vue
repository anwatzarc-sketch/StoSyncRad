<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, post } from '@/lib/api';
import { relativeTime } from '@/lib/format';

const items = ref<Record<string, any>[]>([]);
const status = ref<'unread' | ''>('unread');
const loading = ref(true);
const error = ref<unknown>(null);

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

onMounted(load);
</script>

<template>
    <PageHeader title="Notifications">
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
</template>
