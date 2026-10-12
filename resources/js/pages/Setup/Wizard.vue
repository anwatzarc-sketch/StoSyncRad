<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import EnumSelect from '@/components/ui/EnumSelect.vue';
import SchemaForm from '@/components/schema/SchemaForm.vue';
import BrandEditor from '@/components/BrandEditor.vue';
import RoleBuilder from '@/components/RoleBuilder.vue';
import ConfigStep from '@/components/setup/ConfigStep.vue';
import OrganizationEditor from '@/components/setup/OrganizationEditor.vue';
import { ApiError, download, get, post } from '@/lib/api';
import { loadEnums } from '@/lib/meta';
import { formatDateTime } from '@/lib/format';
import { notify } from '@/lib/toast';
import type { BranchDraft, BrandForm, ConfigKey } from '@/types/shared';

/**
 * Platform Setup wizard (Chapter 02, Chapter 12 §12.5): ordered steps, each validated and applied
 * by the server, resumable, and finished by "Lock & Launch". Nothing else in the platform answers
 * until setup is locked.
 */
interface StepInfo { step: string; label: string; order: number; optional: boolean; completed: boolean; completed_at: string | null }
interface SetupState { state: string; current_step: string | null; deployment_id: string; steps: StepInfo[]; authorized: boolean; super_admin_signed_in: boolean;
    preflight: Array<{ check: string; status: string; message: string; remediation: string | null }> }

const props = defineProps<{ setup: SetupState }>();
const state = ref<SetupState>(props.setup);
const active = ref<string>(props.setup.current_step ?? 'welcome');
const current = ref<Record<string, any> | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const result = ref<Record<string, any> | null>(null);
const token = ref(new URLSearchParams(window.location.search).get('token') ?? '');

// Step payload models
const welcome = reactive({ acknowledge: false, deployment_label: '' });
const admin = reactive({ full_name: '', email: '', username: '', password: '', confirm: '', mfa_code: '' });
const mfa = ref<{ secret: string; otpauth_uri: string; qr_svg: string } | null>(null);
const company = ref<Record<string, unknown>>({});
const brand = ref<BrandForm | null>(null);
const config = ref<Record<string, unknown>>({});
const identity = reactive({ current_password: '', new_password: '', new_password_confirmation: '' });
const taxonomy = reactive<{ mode: 'all' | 'none' | 'some'; groups: string[] }>({ mode: 'all', groups: [] });
const branches = ref<BranchDraft[]>([]);
const sequences = ref<Array<Record<string, any>>>([]);
const sod = ref<Record<string, string>>({});
const tiersText = ref<Record<string, string>>({});
const roles = reactive<{ templates: 'all' | 'none' | 'some'; picked: string[]; custom: Array<{ code: string; name: string; permissions: string[] }> }>({ templates: 'all', picked: [], custom: [] });
const seedReports = ref(true);

const stepInfo = computed(() => state.value.steps.find((s) => s.step === active.value));
const done = computed(() => state.value.steps.filter((s) => s.completed).length);
const fieldErrors = computed(() => (error.value instanceof ApiError ? error.value.fieldErrors() : {}));
const definitions = computed<ConfigKey[]>(() => current.value?.definitions ?? []);
const CONFIG_STEPS = ['localization', 'identity_security', 'catalog_foundations', 'stock_approval_policies', 'reporting', 'notifications_integrations'];
const mfaQr = computed(() => (mfa.value ? `data:image/svg+xml;base64,${btoa(mfa.value.qr_svg)}` : ''));
/** Setup resumed in a new session after the Super Admin exists: sign that account in before continuing. */
const credentials = reactive({ identifier: '', password: '', code: '' });
const needsSignIn = computed(() => state.value.authorized && !state.value.super_admin_signed_in
    && !!state.value.steps.find((s) => s.step === 'super_admin')?.completed);

async function authorize() {
    busy.value = true;
    error.value = null;
    try {
        state.value = (await post('/setup/session', { token: token.value.trim() })).data;
        if (!needsSignIn.value) {
            await open(state.value.current_step ?? 'welcome');
        }
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

async function signInAsAdmin() {
    busy.value = true;
    error.value = null;
    try {
        state.value = (await post('/setup/sign-in', { ...credentials })).data;
        Object.assign(credentials, { password: '', code: '' });
        await open(state.value.current_step ?? active.value);
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

async function open(step: string) {
    active.value = step;
    current.value = null;
    result.value = null;
    error.value = null;
    try {
        current.value = (await get(`/setup/steps/${step}`)).data.current;
        hydrate(step, current.value ?? {});
    } catch (e) {
        error.value = e;
    }
}

function hydrate(step: string, c: Record<string, any>) {
    if (CONFIG_STEPS.includes(step)) {
        const secret = new Set((c.definitions ?? []).filter((d: ConfigKey) => d.sensitive).map((d: ConfigKey) => d.key));
        config.value = Object.fromEntries(Object.entries(c.config ?? {}).map(([k, v]) => [k, secret.has(k) || v === '***' ? undefined : v]));
    }
    if (step === 'company_profile' && c.company) {
        company.value = Object.fromEntries(Object.entries(c.company).filter(([k]) => !['id', 'singleton', 'created_at', 'updated_at', 'created_by', 'updated_by'].includes(k)));
    }
    if (step === 'brand_kit' && c.brand) {
        brand.value = { app_name: c.brand.app_name, app_short_name: c.brand.app_short_name.slice(0, 12), tagline: c.brand.tagline, legal_footer: c.brand.legal_footer,
            support_email: c.brand.support_email, support_phone: c.brand.support_phone, support_url: c.brand.support_url,
            theme: { colors: { ...c.brand.theme.colors }, font: c.brand.theme.font, radius: c.brand.theme.radius, density: c.brand.theme.density },
            assets: {}, contrast_override_reason: null };
    }
    if (step === 'organization' && branches.value.length === 0 && (c.branches ?? []).length === 0) {
        branches.value = [{ code: 'HQ', name: 'Head office', kind: 'headquarters', warehouses: [{ code: 'HQ-MAIN', name: 'Main store', location_pattern: '' }] }];
    }
    if (step === 'document_numbering') {
        sequences.value = (c.sequences ?? []).filter((s: any) => !s.scope_id).map((s: any) => ({ ...s }));
    }
    if (step === 'stock_approval_policies') {
        sod.value = Object.fromEntries((c.sod_rules ?? []).map((r: any) => [r.code, r.rule]));
        tiersText.value = Object.fromEntries((c.approval_policies ?? []).map((p: any) => [p.document_type, JSON.stringify(typeof p.tiers === 'string' ? JSON.parse(p.tiers) : p.tiers, null, 2)]));
    }
}

function payload(step: string): Record<string, unknown> {
    const cfg = () => Object.fromEntries(Object.entries(config.value).filter(([, v]) => v !== undefined));
    switch (step) {
        case 'welcome':
            return { acknowledge: welcome.acknowledge, deployment_label: welcome.deployment_label || undefined };
        case 'super_admin':
            return { full_name: admin.full_name, email: admin.email, username: admin.username || undefined, password: admin.password, mfa_code: admin.mfa_code || undefined };
        case 'company_profile':
            return company.value;
        case 'brand_kit':
            return { ...(brand.value ?? {}) };
        case 'identity_security':
            return { config: cfg(), current_password: identity.current_password,
                ...(identity.new_password ? { new_password: identity.new_password, new_password_confirmation: identity.new_password_confirmation } : {}) };
        case 'catalog_foundations':
            return { config: cfg(), taxonomy: taxonomy.mode === 'some' ? taxonomy.groups : taxonomy.mode };
        case 'stock_approval_policies':
            return { config: cfg(), sod_rules: sod.value, approval_tiers: Object.fromEntries(Object.entries(tiersText.value).map(([k, v]) => [k, JSON.parse(v)])) };
        case 'reporting':
            return { config: cfg(), seed_standard_reports: seedReports.value };
        case 'localization':
        case 'notifications_integrations':
            return { config: cfg() };
        case 'organization':
            return { branches: branches.value.map((b) => ({ ...b, warehouses: b.warehouses.map((w) => ({ code: w.code, name: w.name, ...(w.location_pattern ? { location_pattern: w.location_pattern } : {}) })) })) };
        case 'document_numbering':
            return { sequences: Object.fromEntries(sequences.value.map((s) => [s.document_type, { prefix: s.prefix, pattern: s.pattern, scope: s.scope, reset_policy: s.reset_policy }])) };
        case 'roles_access':
            return { templates: roles.templates === 'some' ? roles.picked : roles.templates, roles: roles.custom.filter((r) => r.code && r.name) };
        default:
            return {};
    }
}

async function submit() {
    if (active.value === 'super_admin' && admin.password !== admin.confirm) {
        error.value = new Error('The passwords do not match.');

        return;
    }
    if (active.value === 'identity_security' && identity.new_password && identity.new_password !== identity.new_password_confirmation) {
        error.value = new ApiError(422, { message: 'The new password and its confirmation do not match.', code: 'VALIDATION_FAILED',
            errors: [{ property: 'new_password_confirmation', message: 'The new password and its confirmation do not match.' }] });

        return;
    }
    busy.value = true;
    error.value = null;
    try {
        const r = (await post(`/setup/steps/${active.value}`, payload(active.value))).data;
        result.value = r.result ?? null;
        const { result: _ignored, ...rest } = r;
        state.value = rest as SetupState;
        notify(`${stepInfo.value?.label ?? 'Step'} saved.`);
        if (active.value === 'identity_security') {
            if (result.value?.password_rotated) {
                notify('Your password was changed — use the new one from now on.', 'info', 10000);
            }
            Object.assign(identity, { current_password: '', new_password: '', new_password_confirmation: '' });
        }
        if (!(active.value === 'super_admin' && result.value?.recovery_codes?.length)) {
            await next();
        }
    } catch (e) {
        error.value = e;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
        busy.value = false;
    }
}

async function skip() {
    busy.value = true;
    try {
        state.value = (await post(`/setup/steps/${active.value}/skip`)).data;
        await next();
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

async function next() {
    const following = state.value.steps.find((s) => s.order > (stepInfo.value?.order ?? 0) && !s.completed) ?? state.value.steps.find((s) => !s.completed);
    if (following) {
        await open(following.step);
    }
}

async function issueMfa() {
    try {
        mfa.value = (await post('/setup/mfa/secret', { email: admin.email })).data;
    } catch (e) {
        error.value = e;
    }
}

async function lock() {
    busy.value = true;
    error.value = null;
    try {
        const r = (await post('/setup/lock')).data;
        window.location.assign(r.redirect ?? '/');
    } catch (e) {
        error.value = e;
        busy.value = false;
    }
}

onMounted(async () => {
    if (state.value.authorized) {
        await loadEnums().catch(() => undefined);
        if (!needsSignIn.value) {
            await open(active.value);
        }
    }
});
watch(() => state.value.authorized, (ok) => ok && void loadEnums().catch(() => undefined));
</script>

<template>
    <Head title="Platform Setup" />

    <section v-if="!state.authorized" class="mx-auto max-w-lg">
        <div class="card space-y-4">
            <h1 class="text-xl font-bold">Platform Setup</h1>
            <p class="text-sm text-ink-muted">
                This deployment has not been set up yet. On the server, run <code class="rounded bg-surface-muted px-1">php artisan platform:bootstrap</code>
                and paste the one-time setup token here (or open the printed link).
            </p>
            <ErrorSummary :error="error" />
            <form class="flex gap-2" @submit.prevent="authorize">
                <label for="setup-token" class="sr-only">Setup token</label>
                <input id="setup-token" v-model="token" class="input font-mono" autocomplete="off" placeholder="Setup token" required>
                <UiButton type="submit" variant="primary" :loading="busy">Continue</UiButton>
            </form>
        </div>
    </section>

    <div v-else class="grid gap-6 lg:grid-cols-[17rem_1fr]">
        <nav aria-label="Setup steps" class="card h-fit p-2">
            <p class="px-2 pb-2 text-xs text-ink-muted">{{ done }} of {{ state.steps.length }} steps · deployment {{ state.deployment_id.slice(0, 8) }}</p>
            <ol>
                <li v-for="s in state.steps" :key="s.step">
                    <button type="button" :class="['flex min-h-11 w-full items-center gap-2 rounded px-2 text-left text-sm', active === s.step ? 'bg-surface-muted font-semibold' : 'hover:bg-surface-muted']"
                            :aria-current="active === s.step ? 'step' : undefined" @click="open(s.step)">
                        <span :class="['grid size-6 shrink-0 place-items-center rounded-full text-xs', s.completed ? 'bg-success text-white' : 'bg-surface-muted ring-1 ring-line']">
                            {{ s.completed ? '✓' : s.order }}
                        </span>
                        <span class="flex-1">{{ s.label }}</span>
                        <span v-if="s.optional" class="text-[11px] text-ink-muted">optional</span>
                    </button>
                </li>
            </ol>
        </nav>

        <section v-if="needsSignIn" class="card max-w-lg space-y-4">
            <h1 class="text-xl font-bold">Sign in to continue setup</h1>
            <p class="text-sm text-ink-muted">The Super Admin already exists. Sign in with that account to carry on where you left off — completed steps are kept.</p>
            <ErrorSummary :error="error" />
            <form class="space-y-4" @submit.prevent="signInAsAdmin">
                <div>
                    <label for="si-identifier" class="label">Username or email</label>
                    <input id="si-identifier" v-model="credentials.identifier" class="input" autocomplete="username" autocapitalize="off" required>
                </div>
                <div>
                    <label for="si-password" class="label">Password</label>
                    <input id="si-password" v-model="credentials.password" type="password" class="input" autocomplete="current-password" required
                           :class="{ 'input-invalid': fieldErrors.password }">
                    <p v-if="fieldErrors.password" class="error-text" role="alert">{{ fieldErrors.password }}</p>
                </div>
                <div>
                    <label for="si-code" class="label">Authenticator code (if you set one up)</label>
                    <input id="si-code" v-model="credentials.code" class="input font-mono" inputmode="numeric" autocomplete="one-time-code"
                           :class="{ 'input-invalid': fieldErrors.code }">
                    <p v-if="fieldErrors.code" class="error-text" role="alert">{{ fieldErrors.code }}</p>
                </div>
                <UiButton type="submit" variant="primary" :loading="busy">Sign in</UiButton>
            </form>
        </section>

        <section v-else class="card min-w-0 space-y-5">
            <header>
                <h1 class="text-xl font-bold">{{ stepInfo?.label }}</h1>
                <p v-if="stepInfo?.completed" class="text-sm text-success">Completed {{ formatDateTime(stepInfo.completed_at) }} — you can revisit and save again before launch.</p>
            </header>
            <ErrorSummary :error="error" />
            <p v-if="!current && !error" class="text-sm text-ink-muted">Loading…</p>

            <template v-else-if="current">
                <!-- 1 Welcome -->
                <div v-if="active === 'welcome'" class="space-y-4">
                    <table class="table">
                        <thead><tr><th scope="col">Check</th><th scope="col">Result</th><th scope="col">Detail</th></tr></thead>
                        <tbody>
                            <tr v-for="c in current.preflight" :key="c.check">
                                <td class="font-mono text-xs">{{ c.check }}</td>
                                <td><StatusBadge :value="c.status" :label="c.status.toUpperCase()" /></td>
                                <td>{{ c.message }}<p v-if="c.remediation" class="text-xs text-ink-muted">{{ c.remediation }}</p></td>
                            </tr>
                        </tbody>
                    </table>
                    <div>
                        <label for="w-label" class="label">Deployment label (optional)</label>
                        <input id="w-label" v-model="welcome.deployment_label" class="input" placeholder="e.g. Production — Addis Ababa">
                    </div>
                    <label class="flex min-h-11 items-center gap-3"><input v-model="welcome.acknowledge" type="checkbox" class="size-5 accent-brand"> I accept the licence terms for this deployment.</label>
                </div>

                <!-- 2 Super Admin -->
                <div v-else-if="active === 'super_admin'" class="space-y-4">
                    <div v-if="result?.recovery_codes?.length" class="space-y-3 rounded-brand bg-warning/10 p-4">
                        <p class="font-semibold">Save these recovery codes now — each works once and they are not shown again.</p>
                        <ul class="grid grid-cols-2 gap-2 font-mono text-sm"><li v-for="c in result.recovery_codes" :key="c">{{ c }}</li></ul>
                        <UiButton variant="primary" @click="next">I have saved them — continue</UiButton>
                    </div>
                    <p v-else-if="current.exists" class="text-sm">The Super Admin <strong>{{ current.username }}</strong> exists and is signed in to this setup session.</p>
                    <template v-else>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div><label for="a-name" class="label">Full name *</label><input id="a-name" v-model="admin.full_name" class="input" autocomplete="name"></div>
                            <div><label for="a-email" class="label">Email *</label><input id="a-email" v-model="admin.email" type="email" class="input" autocomplete="email"></div>
                            <div><label for="a-user" class="label">Username (defaults to email)</label><input id="a-user" v-model="admin.username" class="input" autocomplete="username"></div>
                            <div />
                            <div><label for="a-pass" class="label">Password *</label><input id="a-pass" v-model="admin.password" type="password" class="input" autocomplete="new-password"></div>
                            <div><label for="a-pass2" class="label">Repeat password *</label><input id="a-pass2" v-model="admin.confirm" type="password" class="input" autocomplete="new-password"></div>
                        </div>
                        <div v-if="current.mfa_required" class="space-y-3 rounded-brand border border-line p-4">
                            <p class="text-sm font-semibold">Two-step verification is required for the Super Admin.</p>
                            <UiButton v-if="!mfa" :disabled="!admin.email" @click="issueMfa">Show authenticator QR code</UiButton>
                            <template v-else>
                                <img :src="mfaQr" alt="Authenticator QR code" class="size-52 rounded bg-white p-2">
                                <p class="break-all font-mono text-xs text-ink-muted">{{ mfa.secret }}</p>
                                <div class="max-w-xs"><label for="a-code" class="label">6-digit code</label><input id="a-code" v-model="admin.mfa_code" class="input font-mono" inputmode="numeric"></div>
                            </template>
                        </div>
                    </template>
                </div>

                <!-- 3 Company -->
                <SchemaForm v-else-if="active === 'company_profile'" v-model="company" schema-name="company_profile" operation="create" :errors="fieldErrors" />

                <!-- 4 Brand -->
                <BrandEditor v-else-if="active === 'brand_kit' && brand" v-model="brand" upload-url="/setup/brand/assets" />

                <!-- 7 Organization -->
                <OrganizationEditor v-else-if="active === 'organization'" v-model="branches" :existing="current.branches ?? []" />

                <!-- 9 Partners -->
                <p v-else-if="active === 'partners'" class="text-sm text-ink-muted">
                    {{ current.partners }} partners exist. Suppliers, customers and carriers can be imported or added after launch — skip this step if you are not ready.
                </p>

                <!-- 10 Numbering -->
                <div v-else-if="active === 'document_numbering'" class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th scope="col">Document</th><th scope="col">Prefix</th><th scope="col">Pattern</th></tr></thead>
                        <tbody>
                            <tr v-for="s in sequences" :key="s.document_type">
                                <td>{{ s.document_type.replace(/_/g, ' ') }}</td>
                                <td><input v-model="s.prefix" class="input min-h-9 w-28 font-mono uppercase" maxlength="12" :aria-label="`${s.document_type} prefix`"></td>
                                <td><input v-model="s.pattern" class="input min-h-9 font-mono" :aria-label="`${s.document_type} pattern`"></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="help">Tokens: {PREFIX} {WH} {BR} {YYYY} {YY} {MM} {SEQ:6}</p>
                </div>

                <!-- 12 Roles -->
                <div v-else-if="active === 'roles_access'" class="space-y-5">
                    <fieldset class="space-y-2">
                        <legend class="label">Starting roles (editable afterwards)</legend>
                        <label class="flex items-center gap-2 text-sm"><input v-model="roles.templates" type="radio" value="all" class="accent-brand"> All role templates</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="roles.templates" type="radio" value="some" class="accent-brand"> Only some</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="roles.templates" type="radio" value="none" class="accent-brand"> None — I will build my own</label>
                        <div v-if="roles.templates === 'some'" class="grid gap-1 pl-6 md:grid-cols-2">
                            <label v-for="t in current.templates" :key="t.code" class="flex items-start gap-2 text-sm">
                                <input v-model="roles.picked" type="checkbox" :value="t.code" class="mt-1 accent-brand">
                                <span><strong>{{ t.name }}</strong><br><span class="text-ink-muted">{{ t.description }}</span></span>
                            </label>
                        </div>
                    </fieldset>
                    <div v-for="(r, i) in roles.custom" :key="i" class="space-y-3 rounded-brand border border-line p-3">
                        <div class="grid gap-3 md:grid-cols-2">
                            <div><label :for="`rc-${i}`" class="label">Role code</label><input :id="`rc-${i}`" v-model="r.code" class="input font-mono" placeholder="store_supervisor"></div>
                            <div><label :for="`rn-${i}`" class="label">Role name</label><input :id="`rn-${i}`" v-model="r.name" class="input"></div>
                        </div>
                        <RoleBuilder v-model="r.permissions" source="/setup/meta/permissions" />
                        <button type="button" class="text-sm text-danger underline" @click="roles.custom.splice(i, 1)">Remove role</button>
                    </div>
                    <UiButton @click="roles.custom.push({ code: '', name: '', permissions: [] })">+ Custom role</UiButton>
                </div>

                <!-- 15 Opening data -->
                <p v-else-if="active === 'opening_data'" class="text-sm text-ink-muted">
                    Catalog, partner and opening-balance imports are available under Imports after launch; opening balances are created as drafts and posted by an authorised person.
                </p>

                <!-- 16 Review & lock -->
                <div v-else-if="active === 'review_lock'" class="space-y-4">
                    <ul class="divide-y divide-line text-sm">
                        <li v-for="s in state.steps.filter((x) => x.step !== 'review_lock')" :key="s.step" class="flex justify-between py-2">
                            <span>{{ s.label }}</span>
                            <span :class="s.completed ? 'text-success' : s.optional ? 'text-ink-muted' : 'text-danger'">{{ s.completed ? 'Done' : s.optional ? 'Skipped / optional' : 'Required' }}</span>
                        </li>
                    </ul>
                    <div class="flex flex-wrap gap-2">
                        <UiButton icon="download" @click="download('/setup/bundle', 'bundle.json')">Download config bundle</UiButton>
                        <UiButton variant="accent" :loading="busy" @click="lock">Lock & launch</UiButton>
                    </div>
                    <p class="help">After launch, sign-in opens and every setting stays editable in Administration (audited). Setup can only be reopened in maintenance mode.</p>
                </div>

                <!-- Config-driven steps -->
                <template v-if="CONFIG_STEPS.includes(active)">
                    <div v-if="active === 'reporting'" class="rounded-brand bg-surface-muted p-3 text-sm">
                        Reporting connection: <strong>{{ current.probe?.read_only ? 'read-only ✓' : 'NOT read-only' }}</strong> ·
                        {{ current.datasets?.datasets ?? current.datasets?.count ?? '' }} datasets
                        <label class="mt-2 flex items-center gap-2"><input v-model="seedReports" type="checkbox" class="accent-brand"> Add the standard report library</label>
                    </div>
                    <fieldset v-if="active === 'catalog_foundations'" class="space-y-2 rounded-brand bg-surface-muted p-3">
                        <legend class="label">Category taxonomy ({{ current.existing_categories }} categories exist)</legend>
                        <label class="flex items-center gap-2 text-sm"><input v-model="taxonomy.mode" type="radio" value="all" class="accent-brand"> Full standard taxonomy</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="taxonomy.mode" type="radio" value="some" class="accent-brand"> Selected groups</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="taxonomy.mode" type="radio" value="none" class="accent-brand"> None (I will create or import categories)</label>
                        <div v-if="taxonomy.mode === 'some'" class="grid gap-1 pl-6 md:grid-cols-3">
                            <label v-for="g in current.taxonomy_groups" :key="g.code" class="flex items-center gap-2 text-sm">
                                <input v-model="taxonomy.groups" type="checkbox" :value="g.code" class="accent-brand"> {{ g.name }} <span class="text-ink-muted">({{ g.subcategories }})</span>
                            </label>
                        </div>
                    </fieldset>
                    <div v-if="active === 'stock_approval_policies'" class="space-y-4">
                        <fieldset class="space-y-2">
                            <legend class="label">Segregation of duties</legend>
                            <div v-for="r in current.sod_rules" :key="r.code" class="grid gap-2 md:grid-cols-[1fr_16rem] md:items-center">
                                <span class="text-sm">{{ r.description ?? r.code }}</span>
                                <EnumSelect :id="`sod-${r.code}`" enum-code="sod_rule_type" :model-value="sod[r.code]" @update:model-value="sod[r.code] = $event as string" />
                            </div>
                        </fieldset>
                        <details>
                            <summary class="cursor-pointer text-sm font-semibold">Approval tiers by document (advanced)</summary>
                            <div v-for="(text, type) in tiersText" :key="type" class="mt-3">
                                <label :for="`tiers-${type}`" class="label">{{ String(type).replace(/_/g, ' ') }}</label>
                                <textarea :id="`tiers-${type}`" v-model="tiersText[type]" class="input min-h-28 font-mono text-xs" spellcheck="false" />
                            </div>
                        </details>
                    </div>
                    <ConfigStep v-model="config" :definitions="definitions" :errors="fieldErrors" />
                    <div v-if="active === 'identity_security'" class="space-y-4 rounded-brand border border-line p-4">
                        <div class="max-w-md">
                            <label for="id-current" class="label">Your current password <span class="text-danger">*</span></label>
                            <input id="id-current" v-model="identity.current_password" type="password" class="input" autocomplete="off"
                                   :class="{ 'input-invalid': fieldErrors.current_password }" aria-describedby="id-current-help">
                            <p v-if="fieldErrors.current_password" class="error-text" role="alert">{{ fieldErrors.current_password }}</p>
                            <p v-else id="id-current-help" class="help">The Super Admin password you chose in step 2 — it confirms these security settings.</p>
                        </div>
                        <fieldset class="grid gap-4 md:grid-cols-2">
                            <legend class="label md:col-span-2">Change my password (optional — required only if the policy above rejects your current one)</legend>
                            <div>
                                <label for="id-new" class="label">New password</label>
                                <input id="id-new" v-model="identity.new_password" type="password" class="input" autocomplete="new-password"
                                       :class="{ 'input-invalid': fieldErrors.new_password }">
                                <p v-if="fieldErrors.new_password" class="error-text" role="alert">{{ fieldErrors.new_password }}</p>
                            </div>
                            <div>
                                <label for="id-new-confirm" class="label">Repeat the new password</label>
                                <input id="id-new-confirm" v-model="identity.new_password_confirmation" type="password" class="input" autocomplete="new-password"
                                       :disabled="!identity.new_password" :class="{ 'input-invalid': fieldErrors.new_password_confirmation }">
                                <p v-if="fieldErrors.new_password_confirmation" class="error-text" role="alert">{{ fieldErrors.new_password_confirmation }}</p>
                            </div>
                        </fieldset>
                    </div>
                </template>

                <div v-if="active !== 'review_lock' && !(active === 'super_admin' && (current.exists || result?.recovery_codes?.length))"
                     class="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
                    <UiButton v-if="stepInfo?.optional" :disabled="busy" @click="skip">Skip</UiButton>
                    <UiButton variant="primary" :loading="busy" @click="submit">Save and continue</UiButton>
                </div>
                <div v-else-if="active === 'super_admin' && current.exists" class="flex justify-end border-t border-line pt-4">
                    <UiButton variant="primary" @click="next">Continue</UiButton>
                </div>
            </template>
        </section>
    </div>
</template>
