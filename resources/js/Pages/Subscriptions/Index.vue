<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import Drawer from '@/Components/Bss/Drawer.vue';
import StageTracker from '@/Components/Bss/StageTracker.vue';
import Timeline from '@/Components/Bss/Timeline.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

// FE-APP-01 §8 Subscriptions backoffice over SUB-LM (master) + SUB-WF (operation framework).
// One surface: filterable subscription list with summary tiles, a detail drawer with a
// lifecycle StageTracker + MACD action buttons (preview→confirm→POST) + an operation-history
// Timeline, and an operation-monitor panel of recent/in-flight operations. Every fetch is
// guarded so a down endpoint blanks only its own region — load never throws. All copy via t().
const { t, money, dateFmt } = useI18n();

// One normalizer for every list shape SUB-LM/SUB-WF may emit:
// {items:[…]} (DD_API-00 paginated), {data:[…]}, or {data:{items:[…]}}.
const rows = (d) => {
    if (Array.isArray(d)) return d;
    if (Array.isArray(d?.items)) return d.items;
    if (Array.isArray(d?.data)) return d.data;
    if (Array.isArray(d?.data?.items)) return d.data.items;
    return [];
};
const item = (d) => (d?.data && !Array.isArray(d.data) ? d.data : d) ?? null;

// ── transient flash / error banners ───────────────────────────────────────
const notice = ref(null);
const error = ref(null);
const flash = (m) => { notice.value = m; setTimeout(() => (notice.value = null), 4000); };
const fail = (e) => { error.value = e?.response?.data?.message ?? t('Request failed'); setTimeout(() => (error.value = null), 6000); };

// ── list + filters ────────────────────────────────────────────────────────
const loading = ref(true);
const list = ref([]);
const filters = ref({ q: '', status: '' });
const statusOptions = ['ACTIVE', 'PENDING_ACTIVATION', 'SUSPENDED', 'RESTRICTED', 'TERMINATED'];

async function loadList() {
    loading.value = true;
    try {
        const params = {};
        if (filters.value.status) params.status = filters.value.status;
        const { data } = await window.axios.get('/api/subscriptions', { params });
        list.value = rows(data);
    } catch (e) {
        list.value = [];
    } finally {
        loading.value = false;
    }
}

// Client-side text filter across the human-meaningful identifiers.
const filtered = computed(() => {
    const q = filters.value.q.trim().toLowerCase();
    if (!q) return list.value;
    return list.value.filter((s) =>
        [s.subscription_id, s.customer_id, s.account_id, s.package_ref, s.homepass_id]
            .some((v) => String(v ?? '').toLowerCase().includes(q)));
});

const countBy = (codes) => list.value.filter((s) => codes.includes(s.status_code)).length;
const stats = computed(() => [
    { key: 'active', label: 'Active', tone: 'emerald', value: countBy(['ACTIVE']), filter: 'ACTIVE' },
    { key: 'pending', label: 'Pending', tone: 'amber', value: countBy(['PENDING_ACTIVATION', 'CREATED']), filter: 'PENDING_ACTIVATION' },
    { key: 'suspended', label: 'Suspended', tone: 'red', value: countBy(['SUSPENDED', 'RESTRICTED']), filter: 'SUSPENDED' },
    { key: 'terminated', label: 'Terminated', tone: 'gray', value: countBy(['TERMINATED', 'RETIRED']), filter: 'TERMINATED' },
    { key: 'total', label: 'Total', tone: 'indigo', value: list.value.length, filter: '' },
]);
function tileSelect(f) { filters.value.status = f; loadList(); }

const columns = [
    { key: 'subscription_id', label: 'Subscription' },
    { key: 'customer_id', label: 'Customer / Account' },
    { key: 'package_ref', label: 'Package' },
    { key: 'status_code', label: 'Status' },
    { key: 'billing_mode', label: 'Billing' },
];

// ── detail drawer ─────────────────────────────────────────────────────────
const drawerOpen = ref(false);
const detail = ref(null);
const detailLoading = ref(false);
const operations = ref([]);
const inFlight = ref(null);
const confirming = ref(null); // the action key awaiting confirmation

async function openSubscription(row) {
    detail.value = row;
    drawerOpen.value = true;
    detailLoading.value = true;
    confirming.value = null;
    operations.value = [];
    inFlight.value = null;
    try {
        const { data } = await window.axios.get(`/api/subscriptions/${row.subscription_id}`);
        detail.value = item(data) ?? row;
    } catch (e) { /* fall back to the list row */ }
    await loadOperations(row.subscription_id);
    await loadInFlight(row.subscription_id);
    detailLoading.value = false;
}

async function loadOperations(id) {
    try {
        const { data } = await window.axios.get(`/api/subscriptions/${id}/operations`);
        operations.value = rows(data);
    } catch (e) { operations.value = []; }
}
async function loadInFlight(id) {
    try {
        const res = await window.axios.get(`/api/subscriptions/${id}/in-flight-operation`);
        inFlight.value = res.status === 204 ? null : item(res.data);
    } catch (e) { inFlight.value = null; }
}

// ── lifecycle StageTracker ────────────────────────────────────────────────
// Map the current SUB-LM status onto the canonical lifecycle spine. A *_PENDING status
// renders the destination node as 'current'; failures flag the active node red.
const LIFECYCLE = [
    { key: 'PENDING', label: 'Pending', match: ['CREATED', 'PENDING_ACTIVATION'] },
    { key: 'ACTIVE', label: 'Active', match: ['ACTIVE'] },
    { key: 'SUSPENDED', label: 'Suspended / Restricted', match: ['SUSPENDED', 'RESTRICTED', 'PAUSED'] },
    { key: 'TERMINATED', label: 'Terminated', match: ['TERMINATED', 'RETIRED'] },
];
const lifecycleStages = computed(() => {
    const code = detail.value?.status_code ?? '';
    const pending = code.startsWith('PENDING_') && code !== 'PENDING_ACTIVATION';
    // Determine the reached index. A transient PENDING_* keeps the current node 'current'.
    let activeIdx = LIFECYCLE.findIndex((s) => s.match.includes(code));
    if (activeIdx < 0) activeIdx = pending ? 1 : 0; // mid-transition: keep spine on ACTIVE
    const failed = !!detail.value?.last_failure;
    return LIFECYCLE.map((s, i) => ({
        key: s.key,
        label: s.label,
        state: i < activeIdx ? 'done'
            : i === activeIdx ? (failed ? 'failed' : 'current')
                : 'pending',
    }));
});

// ── operation history Timeline ────────────────────────────────────────────
const opTone = (op) => {
    const st = op.final_state ?? op.current_state;
    if (st === 'FAILED') return 'red';
    if (st === 'CANCELLED') return 'amber';
    if (st === 'COMPLETED') return 'green';
    return 'indigo';
};
const historyEvents = computed(() => operations.value.map((op) => ({
    title: String(op.operation_kind ?? '').replaceAll('_', ' '),
    subtitle: `${op.final_state ?? op.current_state ?? ''}${op.failure_reason_code ? ' · ' + op.failure_reason_code : ''}`,
    at: dateFmt(op.completed_at ?? op.created_at),
    tone: opTone(op),
})));

// ── MACD / lifecycle actions ──────────────────────────────────────────────
// Each action declares its endpoint suffix, allowed source statuses, and whether the API
// needs extra input. suspend-np is system-only (BILLING_INTERNAL) → rendered disabled.
const ACTIONS = [
    { key: 'activate', label: 'Activate', path: 'activate', tone: 'emerald', from: ['CREATED', 'PENDING_ACTIVATION'] },
    { key: 'pause', label: 'Suspend', path: 'pause', tone: 'amber', from: ['ACTIVE'], input: { reasonCode: '' } },
    { key: 'resume', label: 'Resume', path: 'resume', tone: 'emerald', from: ['SUSPENDED', 'PAUSED', 'RESTRICTED'] },
    { key: 'upgrade', label: 'Upgrade', path: 'upgrade', tone: 'indigo', from: ['ACTIVE'], input: { targetPackageRef: '' } },
    { key: 'downgrade', label: 'Downgrade', path: 'downgrade', tone: 'indigo', from: ['ACTIVE'], input: { targetPackageRef: '' } },
    { key: 'relocate', label: 'Relocate', path: 'relocate', tone: 'cyan', from: ['ACTIVE'], input: { targetHomepassId: '' } },
    { key: 'migrate', label: 'Migrate', path: 'migrate', tone: 'cyan', from: ['ACTIVE'], input: { targetHomepassId: '' } },
    { key: 'terminate', label: 'Terminate', path: 'terminate', tone: 'red', from: ['ACTIVE', 'SUSPENDED', 'RESTRICTED', 'PAUSED'], input: { reasonCode: '' } },
];
// suspend-np exists but is gated to the BILLING_INTERNAL service account → not operator-wireable.
const SYSTEM_ACTIONS = [{ key: 'suspend-np', label: 'Suspend (non-payment)' }];

const actionInput = ref({});
const submitting = ref(false);

const allowed = (a) => {
    const code = detail.value?.status_code ?? '';
    if (code.startsWith('PENDING_')) return false; // an operation is mid-flight
    return a.from.includes(code);
};

function startAction(a) {
    confirming.value = a.key;
    actionInput.value = { ...(a.input ?? {}) };
}
function cancelAction() {
    confirming.value = null;
    actionInput.value = {};
}
async function submitAction(a) {
    submitting.value = true;
    try {
        await window.axios.post(`/api/subscriptions/${detail.value.subscription_id}/${a.path}`,
            a.input ? actionInput.value : {},
            { headers: { 'Idempotency-Key': `${a.key}-${detail.value.subscription_id}-${Date.now()}` } });
        flash(t('Operation submitted'));
        confirming.value = null;
        actionInput.value = {};
        // refresh detail + operation monitor
        await openSubscription({ subscription_id: detail.value.subscription_id });
        await loadMonitor();
        await loadList();
    } catch (e) {
        fail(e);
    } finally {
        submitting.value = false;
    }
}

// Cancel an in-flight framework operation (SUB-WF-FRAMEWORK-01 §8.2).
async function cancelOperation(op) {
    try {
        await window.axios.post(`/api/subscription-operations/${op.operation_id}/cancel`, { cancelReason: 'OPERATOR_CANCEL' });
        flash(t('Operation cancelled'));
        if (detail.value) {
            await loadOperations(detail.value.subscription_id);
            await loadInFlight(detail.value.subscription_id);
        }
        await loadMonitor();
    } catch (e) { fail(e); }
}

// ── operation monitor ─────────────────────────────────────────────────────
// There is no global operations index endpoint; the monitor aggregates the in-flight
// operation of each subscription currently in a PENDING_* (transitioning) status.
const monitor = ref([]);
const monitorLoading = ref(false);
const monitorColumns = [
    { key: 'operation_kind', label: 'Operation' },
    { key: 'subscription_id', label: 'Subscription' },
    { key: 'current_state', label: 'State' },
    { key: 'created_at', label: 'Started' },
];
async function loadMonitor() {
    monitorLoading.value = true;
    try {
        const transitioning = list.value.filter((s) => (s.status_code ?? '').startsWith('PENDING_'));
        const results = await Promise.all(transitioning.slice(0, 25).map(async (s) => {
            try {
                const res = await window.axios.get(`/api/subscriptions/${s.subscription_id}/in-flight-operation`);
                return res.status === 204 ? null : item(res.data);
            } catch (e) { return null; }
        }));
        monitor.value = results.filter(Boolean);
    } catch (e) {
        monitor.value = [];
    } finally {
        monitorLoading.value = false;
    }
}

const opState = (op) => op.final_state ?? op.current_state;

onMounted(async () => {
    await loadList();
    await loadMonitor();
});
</script>

<template>
    <Head :title="t('Subscriptions')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="Subscriptions" :crumbs="[{ label: 'Operations' }, { label: 'Subscriptions' }]">
                <template #actions>
                    <button class="rounded-lg bg-op px-3 py-1.5 text-xs font-medium text-white hover:bg-op-soft0"
                        @click="loadList(); loadMonitor()">{{ t('Refresh') }}</button>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-5">
            <p v-if="notice" class="rounded-lg bg-emerald-50 p-2 text-sm text-emerald-700 ring-1 ring-emerald-100">{{ notice }}</p>
            <p v-if="error" class="rounded-lg bg-red-50 p-2 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</p>

            <!-- Summary tiles -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
                <StatCard v-for="s in stats" :key="s.key" :label="s.label" :tone="s.tone"
                    :value="s.value" :loading="loading" @select="tileSelect(s.filter)" />
            </div>

            <!-- Filter bar + list -->
            <Panel title="Subscriptions" subtitle="SUB-LM master · click a row for lifecycle &amp; MACD actions">
                <template #actions>
                    <div class="flex items-center gap-2">
                        <input v-model="filters.q" :placeholder="t('Search id, customer, package…')"
                            class="w-56 rounded-lg border-gray-200 px-2 py-1 text-sm focus:border-op focus:ring-op" />
                        <select v-model="filters.status" @change="loadList"
                            class="rounded-lg border-gray-200 px-2 py-1 text-sm focus:border-op focus:ring-op">
                            <option value="">{{ t('All statuses') }}</option>
                            <option v-for="o in statusOptions" :key="o" :value="o">{{ t(o.replaceAll('_', ' ')) }}</option>
                        </select>
                    </div>
                </template>

                <DataTable :columns="columns" :rows="filtered" row-key="subscription_id"
                    :loading="loading" empty="No subscriptions match." @select="openSubscription">
                    <template #cell-subscription_id="{ row }">
                        <span class="font-mono text-xs text-gray-700">{{ row.subscription_id }}</span>
                    </template>
                    <template #cell-customer_id="{ row }">
                        <div class="leading-tight">
                            <div class="text-xs text-gray-700">{{ row.customer_id }}</div>
                            <div class="font-mono text-[10px] text-gray-400">{{ row.account_id }}</div>
                        </div>
                    </template>
                    <template #cell-package_ref="{ row }">
                        <span class="font-mono text-xs">{{ row.package_ref }}</span>
                    </template>
                    <template #cell-status_code="{ row }">
                        <StatusBadge :status="row.status_code" />
                    </template>
                    <template #cell-billing_mode="{ row }">
                        <span class="text-xs text-gray-500">{{ t(row.billing_mode ?? '—') }} · {{ row.currency }}</span>
                    </template>
                </DataTable>
            </Panel>

            <!-- Operation monitor -->
            <Panel title="Operation monitor" subtitle="In-flight SUB-WF / MACD operations (subscriptions mid-transition)">
                <template #actions>
                    <button class="text-xs text-op hover:underline" @click="loadMonitor">{{ t('Reload') }}</button>
                </template>
                <DataTable :columns="monitorColumns" :rows="monitor" row-key="operation_id"
                    :loading="monitorLoading" empty="No operations in flight.">
                    <template #cell-operation_kind="{ row }">
                        <span class="font-medium text-gray-700">{{ t(String(row.operation_kind ?? '').replaceAll('_', ' ')) }}</span>
                    </template>
                    <template #cell-subscription_id="{ row }">
                        <span class="font-mono text-xs text-gray-500">{{ row.subscription_id }}</span>
                    </template>
                    <template #cell-current_state="{ row }">
                        <StatusBadge :status="opState(row)" />
                    </template>
                    <template #cell-created_at="{ row }">
                        <span class="text-xs text-gray-500">{{ dateFmt(row.created_at) }}</span>
                    </template>
                    <template #row-actions="{ row }">
                        <button v-if="!row.final_state" class="rounded border border-red-200 px-2 py-0.5 text-xs text-red-600 hover:bg-red-50"
                            @click="cancelOperation(row)">{{ t('Cancel') }}</button>
                    </template>
                </DataTable>
            </Panel>
        </div>

        <!-- Detail drawer -->
        <Drawer v-model:open="drawerOpen" :title="detail?.subscription_id ?? t('Subscription')" width="max-w-2xl">
            <div v-if="detail" class="space-y-5">
                <!-- summary -->
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                    <div class="mb-3 flex items-center justify-between">
                        <StatusBadge :status="detail.status_code" />
                        <span class="text-xs text-gray-400">{{ t(detail.billing_mode ?? '') }} · {{ detail.currency }}</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div><dt class="text-xs text-gray-400">{{ t('Customer') }}</dt><dd class="text-gray-700">{{ detail.customer_id }}</dd></div>
                        <div><dt class="text-xs text-gray-400">{{ t('Account') }}</dt><dd class="text-gray-700">{{ detail.account_id }}</dd></div>
                        <div><dt class="text-xs text-gray-400">{{ t('Package') }}</dt><dd class="font-mono text-xs text-gray-700">{{ detail.package_ref }}</dd></div>
                        <div><dt class="text-xs text-gray-400">{{ t('Homepass') }}</dt><dd class="font-mono text-xs text-gray-700">{{ detail.homepass_id ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-400">{{ t('Activated') }}</dt><dd class="text-gray-700">{{ detail.activated_at ? dateFmt(detail.activated_at) : '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-400">{{ t('Cycle model') }}</dt><dd class="text-gray-700">{{ t(detail.cycle_model ?? '—') }}</dd></div>
                    </dl>
                </div>

                <!-- lifecycle StageTracker -->
                <Panel title="Lifecycle">
                    <StageTracker :stages="lifecycleStages" />
                    <p v-if="detail.last_failure" class="mt-3 rounded bg-red-50 p-2 text-xs text-red-600">
                        {{ t('Last failure') }}: {{ detail.last_failure?.reason ?? detail.last_failure?.code ?? t('see operation history') }}
                    </p>
                </Panel>

                <!-- in-flight banner -->
                <div v-if="inFlight" class="flex items-center justify-between rounded-xl bg-op-soft p-3 ring-1 ring-op">
                    <div class="text-sm">
                        <span class="font-medium text-op">{{ t(String(inFlight.operation_kind ?? '').replaceAll('_', ' ')) }}</span>
                        <span class="ml-2 text-xs text-op">{{ t('in flight') }} · {{ inFlight.current_state }}</span>
                    </div>
                    <button class="rounded border border-op px-2 py-0.5 text-xs text-op hover:bg-op-soft"
                        @click="cancelOperation(inFlight)">{{ t('Cancel') }}</button>
                </div>

                <!-- MACD actions -->
                <Panel title="Actions" subtitle="Available MACD / lifecycle operations (SUB-WF)">
                    <div class="flex flex-wrap gap-2">
                        <button v-for="a in ACTIONS" :key="a.key" :disabled="!allowed(a)"
                            @click="startAction(a)"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium ring-1 transition disabled:cursor-not-allowed disabled:opacity-40"
                            :class="confirming === a.key
                                ? 'bg-gray-900 text-white ring-gray-900'
                                : 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50'">
                            {{ t(a.label) }}
                        </button>
                        <button v-for="a in SYSTEM_ACTIONS" :key="a.key" disabled
                            class="cursor-not-allowed rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-300 ring-1 ring-gray-100"
                            :title="t('System-only (BILLING_INTERNAL)')">
                            {{ t(a.label) }}
                        </button>
                    </div>

                    <!-- preview → confirm panel -->
                    <div v-for="a in ACTIONS" :key="a.key + '-confirm'">
                        <div v-if="confirming === a.key" class="mt-3 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-200">
                            <p class="mb-2 text-sm font-medium text-gray-700">{{ t('Confirm') }}: {{ t(a.label) }}</p>
                            <div v-if="a.input" class="mb-3 space-y-2">
                                <div v-for="(_, field) in actionInput" :key="field">
                                    <label class="block text-[11px] uppercase tracking-wide text-gray-400">{{ t(field) }}</label>
                                    <input v-model="actionInput[field]"
                                        class="w-full rounded border-gray-200 px-2 py-1 text-sm focus:border-op focus:ring-op" />
                                </div>
                            </div>
                            <p class="mb-3 text-xs text-gray-500">
                                {{ t('This will trigger the operation on subscription') }}
                                <span class="font-mono">{{ detail.subscription_id }}</span>.
                            </p>
                            <div class="flex gap-2">
                                <button :disabled="submitting" @click="submitAction(a)"
                                    class="rounded-lg bg-op px-3 py-1.5 text-xs font-medium text-white hover:bg-op-soft0 disabled:opacity-50">
                                    {{ submitting ? t('Submitting…') : t('Confirm &amp; submit') }}
                                </button>
                                <button :disabled="submitting" @click="cancelAction"
                                    class="rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50">
                                    {{ t('Cancel') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Panel>

                <!-- operation history Timeline -->
                <Panel title="Operation history" subtitle="SUB-WF operation log">
                    <div v-if="detailLoading" class="text-sm text-gray-400">{{ t('Loading…') }}</div>
                    <Timeline v-else :events="historyEvents" />
                </Panel>
            </div>
        </Drawer>
    </AuthenticatedLayout>
</template>
