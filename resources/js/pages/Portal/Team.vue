<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import FormField from '@/components/ui/FormField.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { get, post } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { notify } from '@/lib/toast';

/**
 * The company's people in the portal. Everyone sees their colleagues; administrators invite new
 * colleagues, make them administrators and remove their access. The server enforces every rule
 * (own company only, at least one administrator, no removing yourself, user limit).
 */
interface Member {
    id: string;
    name: string;
    email: string;
    status: string;
    administrator: boolean;
    is_me: boolean;
    last_login_at: string | null;
    joined_at: string | null;
}
interface Invitation {
    id: string;
    email: string;
    full_name: string;
    administrator: boolean;
    expires_at: string;
    invited_by: string | null;
}

const team = ref<{ members: Member[]; invitations: Invitation[]; max_members: number; can_manage: boolean } | null>(null);
const error = ref<unknown>(null);
const busy = ref<string | null>(null);

const inviting = ref(false);
const invite = reactive({ full_name: '', email: '', as_admin: false });
const inviteError = ref<unknown>(null);

const used = computed(() => (team.value ? team.value.members.filter((m) => m.status !== 'deactivated').length + team.value.invitations.length : 0));

async function load() {
    try {
        team.value = (await get('/api/v1/portal/team')).data;
    } catch (e) {
        error.value = e;
    }
}

onMounted(load);

async function sendInvite() {
    busy.value = 'invite';
    inviteError.value = null;
    try {
        await post('/api/v1/portal/team/invitations', { full_name: invite.full_name, email: invite.email, as_admin: invite.as_admin });
        notify(`Invitation sent to ${invite.email}.`);
        Object.assign(invite, { full_name: '', email: '', as_admin: false });
        inviting.value = false;
        await load();
    } catch (e) {
        inviteError.value = e;
    } finally {
        busy.value = null;
    }
}

async function act(key: string, url: string, body: unknown, message: string, confirmText?: string) {
    if (confirmText && !window.confirm(confirmText)) {
        return;
    }
    busy.value = key;
    error.value = null;
    try {
        await post(url, body);
        notify(message);
        await load();
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = null;
    }
}

const setAdmin = (m: Member, administrator: boolean) =>
    act(`role:${m.id}`, `/api/v1/portal/team/members/${m.id}/role`, { administrator }, administrator ? `${m.name} is now an administrator.` : `${m.name} is now a regular user.`);
const remove = (m: Member) =>
    act(`off:${m.id}`, `/api/v1/portal/team/members/${m.id}/deactivate`, undefined, `${m.name} can no longer sign in.`,
        `Remove ${m.name}'s access? They are signed out everywhere and can no longer sign in.`);
const restore = (m: Member) => act(`on:${m.id}`, `/api/v1/portal/team/members/${m.id}/reactivate`, undefined, `${m.name} can sign in again.`);
const revoke = (i: Invitation) =>
    act(`inv:${i.id}`, `/api/v1/portal/team/invitations/${i.id}/revoke`, undefined, `Invitation for ${i.email} cancelled.`, `Cancel the invitation for ${i.email}?`);
</script>

<template>
    <Head title="Team" />
    <PageHeader title="Team" subtitle="Colleagues who can use the portal for your company">
        <template v-if="team?.can_manage" #actions>
            <UiButton variant="accent" icon="plus" @click="inviting = true">Invite a colleague</UiButton>
        </template>
    </PageHeader>
    <ErrorSummary :error="error" />

    <div v-if="team" class="space-y-6">
        <p v-if="team.max_members > 0" class="text-sm text-ink-muted">{{ used }} of {{ team.max_members }} portal users in use (including open invitations).</p>
        <p v-if="!team.can_manage" class="text-sm text-ink-muted">Ask one of your administrators to invite or remove colleagues.</p>

        <section class="card">
            <h2 class="mb-3 text-lg font-semibold">People</h2>
            <ul class="divide-y divide-line">
                <li v-for="m in team.members" :key="m.id" class="flex flex-wrap items-center gap-3 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">
                            {{ m.name }}<span v-if="m.is_me" class="text-ink-muted"> (you)</span>
                            <span v-if="m.administrator" class="ml-2 rounded-full bg-accent/10 px-2 py-0.5 text-xs font-semibold text-accent">Administrator</span>
                        </span>
                        <span class="block truncate text-sm text-ink-muted">
                            {{ m.email }} · {{ m.last_login_at ? `last signed in ${formatDateTime(m.last_login_at)}` : 'never signed in' }}
                        </span>
                    </span>
                    <StatusBadge :value="m.status" :label="m.status === 'deactivated' ? 'Removed' : m.status === 'pending_activation' ? 'Setting up' : m.status === 'active' ? 'Active' : m.status" />
                    <template v-if="team.can_manage && !m.is_me">
                        <template v-if="m.status === 'active'">
                            <UiButton v-if="!m.administrator" :loading="busy === `role:${m.id}`" @click="setAdmin(m, true)">Make administrator</UiButton>
                            <UiButton v-else :loading="busy === `role:${m.id}`" @click="setAdmin(m, false)">Make regular user</UiButton>
                        </template>
                        <UiButton v-if="m.status !== 'deactivated'" variant="secondary" :loading="busy === `off:${m.id}`" @click="remove(m)">Remove access</UiButton>
                        <UiButton v-else :loading="busy === `on:${m.id}`" @click="restore(m)">Give access again</UiButton>
                    </template>
                </li>
            </ul>
        </section>

        <section v-if="team.invitations.length" class="card">
            <h2 class="mb-3 text-lg font-semibold">Open invitations</h2>
            <ul class="divide-y divide-line">
                <li v-for="i in team.invitations" :key="i.id" class="flex flex-wrap items-center gap-3 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">
                            {{ i.full_name }}
                            <span v-if="i.administrator" class="ml-2 rounded-full bg-accent/10 px-2 py-0.5 text-xs font-semibold text-accent">Administrator</span>
                        </span>
                        <span class="block truncate text-sm text-ink-muted">{{ i.email }} · valid until {{ formatDate(i.expires_at) }}</span>
                    </span>
                    <UiButton v-if="team.can_manage" variant="secondary" :loading="busy === `inv:${i.id}`" @click="revoke(i)">Cancel invitation</UiButton>
                </li>
            </ul>
        </section>
    </div>
    <div v-else-if="!error" class="h-40 animate-pulse rounded-brand bg-surface" aria-busy="true" />

    <ModalDialog :open="inviting" title="Invite a colleague" @close="inviting = false">
        <form id="invite-form" class="space-y-4" @submit.prevent="sendInvite">
            <ErrorSummary :error="inviteError" />
            <FormField id="invite-name" label="Name" required>
                <input id="invite-name" v-model="invite.full_name" class="input" autocomplete="off" required maxlength="200" />
            </FormField>
            <FormField id="invite-email" label="Email" required help="They receive a link to set a password and two-step verification.">
                <input id="invite-email" v-model="invite.email" type="email" class="input" autocomplete="off" required />
            </FormField>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="invite.as_admin" type="checkbox" class="mt-1" />
                <span>Administrator — can invite and remove colleagues</span>
            </label>
        </form>
        <template #footer>
            <UiButton @click="inviting = false">Cancel</UiButton>
            <UiButton type="submit" form="invite-form" variant="accent" :loading="busy === 'invite'">Send invitation</UiButton>
        </template>
    </ModalDialog>
</template>
