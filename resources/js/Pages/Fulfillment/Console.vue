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

// FE-APP-01 §10 — Fulfillment backoffice surface over the FUL-02/03/04/05/07 backends.
// THE showcase for "view the onboarding process VISUALLY": every fulfillment order is rendered
// as a horizontal StageTracker walking the onboarding journey, with the step ledger as a
// Timeline. Reads the real Fulfillment order API; the termination monitor reads Billing's
// dunning pending-termination-review. Surfaces with no backend show "not available" instead of
// 404ing. Holds no business state — all copy flows through i18n (t / money / dateFmt).
const { t, dateFmt } = useI18n();

// One normalizer for every list shape the platform emits: { items } (paginated/wrapped),
// { data } (paginated), or a bare array. Returns [] for anything else.
const listOf = (d) => {
    if (Array.isArray(d)) return d;
    for (const k of ['items', 'data']) {
        const v = d?.[k];
        if (Array.isArray(v)) return v;
        if (Array.isArray(v?.items)) return v.items;
        if (Array.isArray(v?.data)) return v.data;
    }
    return [];
};

// The onboarding journey, in order. The FulfillmentOrder.status vocabulary IS the stage key.
const JOURNEY = [
    ['CAPTURED', 'Captured'],
    ['AWAITING_KYC', 'KYC'],
    ['AWAITING_PAYMENT', 'Payment'],
    ['AWAITING_INSTALL', 'Install'],
    ['ACTIVATING', 'Activating'],
    ['COMPLETED', 'Active'],
];
const ORDER_INDEX = Object.fromEntries(JOURNEY.map(([k], i) => [k, i]));

// Map the order-step ledger (CAPTURE|VALIDATE|KYC|PAYMENT|INSTALL|SUBSCRIPTION|ACTIVATION) onto
// the journey stages, so a step's DONE/FAILED result lights up the right tracker node.
const STEP_TO_STAGE = {
    CAPTURE: 'CAPTURED', VALIDATE: 'CAPTURED', KYC: 'AWAITING_KYC', PAYMENT: 'AWAITING_PAYMENT',
    INSTALL: 'AWAITING_INSTALL', SUBSCRIPTION: 'AWAITING_INSTALL', ACTIVATION: 'ACTIVATING',
};

const tab = ref('orders');
const tabs = [
    ['orders', 'Order monitor'],
    ['activation', 'Activation'],
    ['restriction', 'Restriction'],
    ['termination', 'Termination'],
];

const error = ref(null);
const fail = (e, ctx) => { error.value = e?.response?.data?.message ?? t(ctx ?? 'Request failed'); setTimeout(() => (error.value = null), 6000); };
const notice = ref(null);
const flash = (msg) => { notice.value = msg; setTimeout(() => (notice.value = null), 4000); };

// --- Orders (FUL-02/03) ---
const orders = ref([]);
const loadingOrders = ref(true);
async function loadOrders() {
    loadingOrders.value = true;
    try {
        orders.value = listOf((await window.axios.get('/api/fulfillment-orders', { params: { size: 100 } })).data);
    } catch (e) { orders.value = []; fail(e, 'Could not load orders'); } finally { loadingOrders.value = false; }
}

// Per-stage in-flight counts for the summary StatCards.
const stageCounts = computed(() => {
    const c = Object.fromEntries(JOURNEY.map(([k]) => [k, 0]));
    for (const o of orders.value) if (o.status in c) c[o.status] += 1;
    return c;
});
const stageTone = { CAPTURED: 'gray', AWAITING_KYC: 'amber', AWAITING_PAYMENT: 'amber', AWAITING_INSTALL: 'cyan', ACTIVATING: 'indigo', COMPLETED: 'emerald' };

const orderColumns = [
    { key: 'order_id', label: 'Order', class: 'font-mono text-xs' },
    { key: 'account_id', label: 'Account', class: 'font-mono text-xs' },
    { key: 'package_ref', label: 'Package' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Created' },
];

// --- Order drawer (FUL-02 journey + step ledger) ---
const open = ref(false);
const detail = ref(null);
const loadingDetail = ref(false);
async function openOrder(row) {
    open.value = true; detail.value = null; loadingDetail.value = true;
    try {
        // show returns the order with steps loaded; fall back to the row if the fetch fails.
        const { data } = await window.axios.get(`/api/fulfillment-orders/${row.order_id}`);
        detail.value = data?.data ?? data ?? row;
    } catch (e) { detail.value = { ...row, steps: [] }; fail(e, 'Could not load order'); } finally { loadingDetail.value = false; }
}

// Build the StageTracker nodes for the open order: stages before the current one (or that have a
// DONE step) are done, the order's status stage is current, a FAILED step marks its stage failed.
const trackerStages = computed(() => {
    const o = detail.value;
    if (!o) return [];
    const steps = listOf(o.steps);
    const failedStages = new Set();
    const doneStages = new Set();
    const atByStage = {};
    for (const s of steps) {
        const stage = STEP_TO_STAGE[s.step];
        if (!stage) continue;
        if (s.status === 'FAILED') failedStages.add(stage);
        if (s.status === 'DONE') { doneStages.add(stage); if (s.completed_at) atByStage[stage] = dateFmt(s.completed_at); }
    }
    const cancelled = o.status === 'CANCELLED';
    const completed = o.status === 'COMPLETED';
    const curIdx = ORDER_INDEX[o.status] ?? 0;
    return JOURNEY.map(([key, label], i) => {
        let state;
        if (failedStages.has(key)) state = 'failed';
        else if (completed) state = 'done';
        else if (cancelled) state = i < curIdx ? 'done' : 'pending';
        else if (i < curIdx || doneStages.has(key)) state = 'done';
        else if (i === curIdx) state = 'current';
        else state = 'pending';
        return { key, label, state, at: atByStage[key] };
    });
});

// The step ledger as a vertical Timeline.
const stepEvents = computed(() => {
    const steps = listOf(detail.value?.steps);
    return steps.map((s) => ({
        title: String(s.step ?? '').replace(/_/g, ' '),
        subtitle: s.result ? JSON.stringify(s.result) : (s.status ?? ''),
        at: dateFmt(s.completed_at ?? s.created_at),
        tone: s.status === 'FAILED' ? 'red' : s.status === 'DONE' ? 'green' : s.status === 'SKIPPED' ? 'gray' : 'amber',
    }));
});

const canConfirmInstall = computed(() => ['CAPTURED', 'AWAITING_INSTALL'].includes(detail.value?.status));
const canCancel = computed(() => detail.value && !['COMPLETED', 'CANCELLED'].includes(detail.value.status));

// FUL-03 activation: confirm install completes the journey through activation.
async function confirmInstall() {
    if (!detail.value) return;
    try {
        await window.axios.post(`/api/fulfillment-orders/${detail.value.order_id}/complete`, {}, { headers: { 'Idempotency-Key': 'ful-complete-' + detail.value.order_id } });
        flash(t('Install confirmed — activation triggered'));
        await openOrder(detail.value); await loadOrders();
    } catch (e) { fail(e, 'Could not confirm install'); }
}
async function cancelOrder() {
    if (!detail.value) return;
    try {
        await window.axios.post(`/api/fulfillment-orders/${detail.value.order_id}/cancel`, { reason: 'Cancelled from console' });
        flash(t('Order cancelled')); await openOrder(detail.value); await loadOrders();
    } catch (e) { fail(e, 'Could not cancel order'); }
}

// --- Activation monitor (FUL-03): orders mid-activation, derived from the order list. ---
const activationRows = computed(() => orders.value.filter((o) => ['AWAITING_INSTALL', 'ACTIVATING'].includes(o.status)));
const activationColumns = [
    { key: 'order_id', label: 'Order', class: 'font-mono text-xs' },
    { key: 'account_id', label: 'Account', class: 'font-mono text-xs' },
    { key: 'package_ref', label: 'Package' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Created' },
];

// --- Restriction monitor (FUL-04 / SUB-WF-RESTRICT-01): no global list endpoint exists —
// restrictions are a per-subscription sub-resource. Show "not available" rather than 404. ---
const restrictionAvailable = false;

// --- Termination monitor (FUL-05): Billing dunning pending-termination-review is the real
// cross-module list of accounts queued for termination. ---
const terminations = ref([]);
const terminationAvailable = ref(true);
const loadingTermination = ref(false);
const terminationColumns = [
    { key: 'account_id', label: 'Account', class: 'font-mono text-xs' },
    { key: 'status', label: 'Status' },
    { key: 'current_level', label: 'Level' },
    { key: 'review_due_at', label: 'Review due' },
];
async function loadTerminations() {
    loadingTermination.value = true;
    try {
        terminations.value = listOf((await window.axios.get('/api/dunning/pending-termination-review')).data);
        terminationAvailable.value = true;
    } catch (e) {
        terminations.value = [];
        // 403/404 → surface the endpoint isn't available to this operator/role rather than erroring.
        terminationAvailable.value = false;
    } finally { loadingTermination.value = false; }
}

function selectTab(key) {
    tab.value = key;
    if (key === 'termination' && !terminations.value.length) loadTerminations();
}
function gotoStage(stage) { tab.value = 'orders'; }

onMounted(loadOrders);
</script>

<template>
    <Head :title="t('Fulfillment')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="Fulfillment" :crumbs="[{ label: 'Operations' }, { label: 'Fulfillment' }]">
                <template #actions>
                    <button class="rounded border border-gray-200 bg-white px-3 py-1 text-xs text-gray-600 hover:bg-gray-50" @click="loadOrders">{{ t('Refresh') }}</button>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-5">
            <p v-if="notice" class="rounded bg-emerald-100 p-2 text-sm text-emerald-700">{{ notice }}</p>
            <p v-if="error" class="rounded bg-red-100 p-2 text-sm text-red-700">{{ error }}</p>

            <!-- Summary StatCards: in-flight orders per onboarding stage. -->
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                <StatCard v-for="[key, label] in JOURNEY" :key="key"
                    :label="label" :tone="stageTone[key]" :value="stageCounts[key]" :loading="loadingOrders"
                    @select="gotoStage(key)" />
            </div>

            <div class="flex flex-wrap gap-2">
                <button v-for="[key, label] in tabs" :key="key" @click="selectTab(key)"
                    :class="tab === key ? 'bg-op text-white' : 'bg-white text-gray-600 ring-1 ring-gray-200'"
                    class="rounded px-3 py-1 text-sm transition">{{ t(label) }}</button>
            </div>

            <!-- ORDER MONITOR -->
            <Panel v-if="tab === 'orders'" title="Order monitor" subtitle="Live fulfillment orders — open one to view its onboarding journey (FUL-02)">
                <DataTable :columns="orderColumns" :rows="orders" rowKey="order_id"
                    :loading="loadingOrders" empty="No fulfillment orders." @select="openOrder">
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-created_at="{ value }">{{ dateFmt(value) }}</template>
                </DataTable>
            </Panel>

            <!-- ACTIVATION MONITOR (FUL-03) -->
            <Panel v-if="tab === 'activation'" title="Activation monitor" subtitle="Orders awaiting install / activating (FUL-03)">
                <DataTable :columns="activationColumns" :rows="activationRows" rowKey="order_id"
                    :loading="loadingOrders" empty="Nothing activating right now." @select="openOrder">
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-created_at="{ value }">{{ dateFmt(value) }}</template>
                    <template #row-actions="{ row }">
                        <button class="rounded border border-gray-200 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-50" @click="openOrder(row)">{{ t('Open') }}</button>
                    </template>
                </DataTable>
            </Panel>

            <!-- RESTRICTION MONITOR (FUL-04) — no global list endpoint -->
            <Panel v-if="tab === 'restriction'" title="Restriction monitor" subtitle="Partial-service restrictions (FUL-04 / SUB-WF-RESTRICT-01)">
                <div v-if="!restrictionAvailable" class="rounded-lg bg-gray-50 p-6 text-center text-sm text-gray-400 ring-1 ring-gray-100">
                    {{ t('Not available — restrictions are managed per subscription; no platform-wide restriction monitor is exposed yet.') }}
                </div>
            </Panel>

            <!-- TERMINATION MONITOR (FUL-05) — Billing dunning pending-termination-review -->
            <Panel v-if="tab === 'termination'" title="Termination monitor" subtitle="Accounts pending termination review (FUL-05 via Billing dunning)">
                <template #actions>
                    <button class="text-xs text-op hover:underline" @click="loadTerminations">{{ t('Reload') }}</button>
                </template>
                <div v-if="!terminationAvailable" class="rounded-lg bg-gray-50 p-6 text-center text-sm text-gray-400 ring-1 ring-gray-100">
                    {{ t('Not available — the termination-review queue is not exposed to your role.') }}
                </div>
                <DataTable v-else :columns="terminationColumns" :rows="terminations" rowKey="account_id"
                    :loading="loadingTermination" empty="No accounts pending termination review.">
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-review_due_at="{ value }">{{ dateFmt(value) }}</template>
                </DataTable>
            </Panel>
        </div>

        <!-- ORDER DRAWER — the visual onboarding journey -->
        <Drawer v-model:open="open" :title="detail ? `Order ${detail.order_id}` : 'Order'">
            <div v-if="loadingDetail" class="text-sm text-gray-400">{{ t('Loading…') }}</div>
            <div v-else-if="detail" class="space-y-5">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-gray-500">
                    <span><span class="text-gray-400">{{ t('Status') }}:</span> <StatusBadge :status="detail.status" /></span>
                    <span><span class="text-gray-400">{{ t('Account') }}:</span> <span class="font-mono">{{ detail.account_id }}</span></span>
                    <span><span class="text-gray-400">{{ t('Customer') }}:</span> <span class="font-mono">{{ detail.customer_id }}</span></span>
                    <span><span class="text-gray-400">{{ t('Package') }}:</span> {{ detail.package_ref }}</span>
                    <span v-if="detail.billing_mode"><span class="text-gray-400">{{ t('Billing') }}:</span> {{ detail.billing_mode }}</span>
                    <span><span class="text-gray-400">{{ t('Created') }}:</span> {{ dateFmt(detail.created_at) }}</span>
                </div>

                <!-- THE centerpiece: the onboarding journey tracker -->
                <Panel title="Onboarding journey" subtitle="Capture → KYC → Payment → Install → Activating → Active (FUL-02)">
                    <div class="overflow-x-auto pb-1">
                        <StageTracker :stages="trackerStages" />
                    </div>
                </Panel>

                <!-- Per-stage actions, wired only to real endpoints (FUL-03 complete, FUL-02 cancel) -->
                <div class="flex flex-wrap gap-2">
                    <button v-if="canConfirmInstall" @click="confirmInstall"
                        class="rounded bg-op px-3 py-1 text-sm text-white hover:bg-op-dark">{{ t('Confirm install & activate') }}</button>
                    <button v-if="canCancel" @click="cancelOrder"
                        class="rounded border border-red-200 px-3 py-1 text-sm text-red-600 hover:bg-red-50">{{ t('Cancel order') }}</button>
                </div>

                <Panel title="Step history" subtitle="The order's step ledger">
                    <Timeline :events="stepEvents" />
                </Panel>
            </div>
            <div v-else class="text-sm text-gray-400">{{ t('No order selected.') }}</div>
        </Drawer>
    </AuthenticatedLayout>
</template>
