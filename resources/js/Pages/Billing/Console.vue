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
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

// FE-APP-01 §9 — Billing backoffice console. One tabbed surface over the BIL backends:
//   invoices (BIL-02), payments (BIL-01-PAY-01), adjustments + credit/debit notes (BIL-02-ADJ-01),
//   wallet (BIL-05), dunning (BIL-04) and tax invoices (BIL-02-TAX-01). This page owns no
//   business state — every panel reads/writes the owning Billing API. Endpoints that don't exist
//   degrade to a "not available" empty state instead of 404ing.
const { t, money, dateFmt } = useI18n();

const tab = ref('invoices');
const tabs = [
    ['invoices', 'Invoices'], ['payments', 'Payments'], ['adjustments', 'Adjustments'],
    ['wallet', 'Wallet'], ['dunning', 'Dunning'], ['tax', 'Tax invoices'], ['cycle', 'Cycle close'],
];

const notice = ref(null);
const error = ref(null);
const flash = (msg) => { notice.value = msg; setTimeout(() => (notice.value = null), 4000); };
const fail = (e) => { error.value = e?.response?.data?.message ?? t('Request failed'); setTimeout(() => (error.value = null), 6000); };

// One list-shape normalizer. The Billing API paginates as {items,...}; some reads return
// {items:[...]} bare, others {data:[...]}/{data:{items}}. Read once, normalize to an array.
const rows = (d, ...keys) => {
    for (const k of (keys.length ? keys : ['items', 'data', 'data.items'])) {
        const v = k.split('.').reduce((o, p) => o?.[p], d);
        if (Array.isArray(v)) return v;
    }
    return [];
};
// money() guarded — a row's amount may be null on a degraded read.
const amt = (v) => (v == null || v === '' ? '—' : money(v));
const when = (v) => (v ? dateFmt(v) : '—');
const go = (name) => { try { router.visit(route(name)); } catch (e) { /* route may be gated */ } };

// ---- Summary StatCards -------------------------------------------------------------------
const loadingSummary = ref(true);
const stats = ref({ open: '—', overdue: '—', paymentsToday: '—', dunning: '—' });
async function loadSummary() {
    loadingSummary.value = true;
    // Open + overdue come from the invoice list (status filter); payments today from the payment
    // ledger; dunning from active dunning states. Each is independent — one failing leaves a dash.
    try {
        const { data } = await window.axios.get('/api/invoices', { params: { status: 'OPEN,PARTIALLY_PAID', size: 1 } });
        stats.value.open = data.totalElements ?? rows(data).length;
    } catch (e) { /* leave dash */ }
    try {
        const { data } = await window.axios.get('/api/invoices', { params: { status: 'OVERDUE', size: 1 } });
        stats.value.overdue = data.totalElements ?? rows(data).length;
    } catch (e) { /* leave dash */ }
    try {
        const { data } = await window.axios.get('/api/payments', { params: { size: 100 } });
        const today = new Date().toISOString().slice(0, 10);
        stats.value.paymentsToday = rows(data).filter((p) => (p.received_at ?? '').slice(0, 10) === today).length;
    } catch (e) { /* leave dash */ }
    try {
        const { data } = await window.axios.get('/api/dunning', { params: { status: 'ACTIVE', size: 1 } });
        stats.value.dunning = data.totalElements ?? rows(data).length;
    } catch (e) { /* leave dash */ }
    loadingSummary.value = false;
}

// ---- Invoices (BIL-02) -------------------------------------------------------------------
const invoices = ref([]);
const invoiceQuery = ref('');
const loadingInvoices = ref(false);
const invoiceCols = [
    { key: 'invoice_id', label: 'Number', class: 'font-mono text-xs' },
    { key: 'account_id', label: 'Account', class: 'font-mono text-xs' },
    { key: 'type', label: 'Type' },
    { key: 'total_amount', label: 'Amount', align: 'right' },
    { key: 'amount_due', label: 'Due', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'issue_date', label: 'Issued' },
];
async function loadInvoices() {
    loadingInvoices.value = true;
    try { invoices.value = rows((await window.axios.get('/api/invoices', { params: { size: 50 } })).data); }
    catch (e) { invoices.value = []; }
    finally { loadingInvoices.value = false; }
}
const filteredInvoices = computed(() => {
    const q = invoiceQuery.value.trim().toLowerCase();
    if (!q) return invoices.value;
    return invoices.value.filter((i) => [i.invoice_id, i.account_id, i.customer_id, i.status]
        .some((v) => String(v ?? '').toLowerCase().includes(q)));
});

const invoiceDrawer = ref(false);
const invoiceDetail = ref(null);
const invoiceLoading = ref(false);
// Invoice lifecycle as a Timeline: issue → due → (payments would surface here if exposed).
const invoiceEvents = computed(() => {
    const d = invoiceDetail.value;
    if (!d) return [];
    const ev = [];
    if (d.issue_date) ev.push({ title: t('Invoice issued'), subtitle: d.invoice_id, at: when(d.issue_date), tone: 'indigo' });
    if (d.due_date) ev.push({ title: t('Payment due'), at: when(d.due_date), tone: 'amber' });
    if (Number(d.amount_paid) > 0) ev.push({ title: t('Payment applied'), subtitle: money(d.amount_paid), tone: 'green' });
    if (d.status === 'PAID') ev.push({ title: t('Invoice settled'), tone: 'green' });
    else if (d.status === 'OVERDUE') ev.push({ title: t('Invoice overdue'), tone: 'red' });
    return ev;
});
async function openInvoice(row) {
    invoiceDrawer.value = true;
    invoiceDetail.value = null;
    invoiceLoading.value = true;
    try { invoiceDetail.value = (await window.axios.get(`/api/invoices/${row.invoice_id}`)).data; }
    catch (e) { fail(e); invoiceDrawer.value = false; }
    finally { invoiceLoading.value = false; }
}
async function issueTaxInvoice(d) {
    try {
        await window.axios.post(`/api/invoices/${d.invoice_id}/tax-invoice`, {}, { headers: { 'Idempotency-Key': 'tax-' + d.invoice_id } });
        flash(t('Tax invoice issued'));
    } catch (e) { fail(e); }
}

// ---- Payments (BIL-01-PAY-01) ------------------------------------------------------------
const payments = ref([]);
const loadingPayments = ref(false);
const paymentCols = [
    { key: 'payment_id', label: 'Reference', class: 'font-mono text-xs' },
    { key: 'account_id', label: 'Account', class: 'font-mono text-xs' },
    { key: 'method', label: 'Method' },
    { key: 'paid_amount', label: 'Amount', align: 'right' },
    { key: 'unallocated_amount', label: 'Unallocated', align: 'right' },
    { key: 'received_at', label: 'Received' },
];
async function loadPayments() {
    loadingPayments.value = true;
    try { payments.value = rows((await window.axios.get('/api/payments', { params: { size: 50 } })).data); }
    catch (e) { payments.value = []; }
    finally { loadingPayments.value = false; }
}
const paymentDrawer = ref(false);
const paymentDetail = ref(null);
function openPayment(row) { paymentDetail.value = row; paymentDrawer.value = true; }
async function allocateSurplus(p) {
    try { await window.axios.post(`/api/payments/${p.payment_id}/allocate-surplus`); flash(t('Surplus allocation requested')); await loadPayments(); }
    catch (e) { fail(e); }
}

// ---- Adjustments (BIL-02-ADJ-01) — approval-gated ----------------------------------------
const adjustments = ref([]);
const reasonCodes = ref([]);
const loadingAdjust = ref(false);
const adjustCols = [
    { key: 'adjustment_id', label: 'Reference', class: 'font-mono text-xs' },
    { key: 'direction', label: 'Direction' },
    { key: 'scope', label: 'Scope' },
    { key: 'amount', label: 'Amount', align: 'right' },
    { key: 'reason_code', label: 'Reason' },
    { key: 'status', label: 'Status' },
];
const newAdjust = ref({ direction: 'CREDIT', scope: 'AMOUNT', parent_invoice_id: '', amount: null, reason_code: '', justification: '' });
async function loadAdjustments() {
    loadingAdjust.value = true;
    try { adjustments.value = rows((await window.axios.get('/api/adjustments', { params: { size: 50 } })).data); }
    catch (e) { adjustments.value = []; }
    try { reasonCodes.value = rows((await window.axios.get('/api/adjustment-reason-codes')).data); }
    catch (e) { reasonCodes.value = []; }
    finally { loadingAdjust.value = false; }
}
async function createAdjustment() {
    try {
        const payload = { ...newAdjust.value };
        if (!payload.parent_invoice_id) delete payload.parent_invoice_id;
        await window.axios.post('/api/adjustments', payload, { headers: { 'Idempotency-Key': 'adj-' + Date.now() } });
        newAdjust.value = { direction: 'CREDIT', scope: 'AMOUNT', parent_invoice_id: '', amount: null, reason_code: '', justification: '' };
        flash(t('Adjustment proposed — pending approval'));
        await loadAdjustments();
    } catch (e) { fail(e); }
}
async function adjustAction(a, action, label) {
    try { await window.axios.post(`/api/adjustments/${a.adjustment_id}/${action}`, {}); flash(t(label)); await loadAdjustments(); }
    catch (e) { fail(e); }
}

// ---- Wallet (BIL-05) — keyed by subscription, so look one up --------------------------------
const walletSub = ref('');
const walletData = ref(null);
const walletErr = ref(false);
const topupAmount = ref(null);
async function lookupWallet() {
    walletData.value = null; walletErr.value = false;
    if (!walletSub.value.trim()) return;
    try { walletData.value = (await window.axios.get(`/api/wallets/${walletSub.value.trim()}/balance`)).data; }
    catch (e) { walletErr.value = true; fail(e); }
}
async function topup() {
    if (!walletData.value || !topupAmount.value) return;
    try {
        await window.axios.post(`/api/wallets/${walletData.value.subscriptionId}/topup`,
            { amount: topupAmount.value }, { headers: { 'Idempotency-Key': 'top-' + Date.now() } });
        topupAmount.value = null; flash(t('Top-up applied')); await lookupWallet();
    } catch (e) { fail(e); }
}

// ---- Dunning (BIL-04) — read-only ladder ---------------------------------------------------
const dunning = ref([]);
const loadingDunning = ref(false);
const dunningCols = [
    { key: 'account_id', label: 'Account', class: 'font-mono text-xs' },
    { key: 'current_level', label: 'Level' },
    { key: 'outstanding_debt_amount', label: 'Outstanding', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'entered_dunning_at', label: 'Since' },
    { key: 'review_due_at', label: 'Review due' },
];
// The BIL-04 dunning ladder. A state's current_level positions it on this spine.
const ladder = [
    [1, 'Warning'], [2, 'Restricted'], [3, 'Suspended'], [4, 'Terminated'],
];
const ladderFor = (level) => ladder.map(([lvl, label]) => ({
    key: lvl, label,
    state: lvl < level ? 'done' : lvl === level ? 'current' : 'pending',
}));
async function loadDunning() {
    loadingDunning.value = true;
    try { dunning.value = rows((await window.axios.get('/api/dunning', { params: { size: 50 } })).data); }
    catch (e) { dunning.value = []; }
    finally { loadingDunning.value = false; }
}

// ---- Tax invoices (BIL-02-TAX-01) ----------------------------------------------------------
const taxInvoices = ref([]);
const taxDash = ref(null);
const loadingTax = ref(false);
const taxCols = [
    { key: 'tax_invoice_id', label: 'Number', class: 'font-mono text-xs' },
    { key: 'invoice_id', label: 'Invoice', class: 'font-mono text-xs' },
    { key: 'total_amount', label: 'Amount', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Created' },
];
async function loadTax() {
    loadingTax.value = true;
    try { taxInvoices.value = rows((await window.axios.get('/api/tax-invoices', { params: { size: 50 } })).data); }
    catch (e) { taxInvoices.value = []; }
    try { taxDash.value = (await window.axios.get('/api/tax-invoices/dashboard')).data; }
    catch (e) { taxDash.value = null; }
    finally { loadingTax.value = false; }
}
const taxPdfUrl = (row) => `/api/tax-invoices/${row.tax_invoice_id}/pdf`;

// ---- Cycle close (BIL cycle-run monitor) ---------------------------------------------------
const cycleRuns = ref([]);
const loadingCycle = ref(false);
const cycleColumns = [
    { key: 'run_id', label: 'Run', class: 'font-mono text-xs' },
    { key: 'started_at', label: 'Started' },
    { key: 'completed_at', label: 'Completed' },
    { key: 'closed', label: 'Closed', align: 'right' },
    { key: 'failed', label: 'Failed', align: 'right' },
];
async function loadCycle() {
    loadingCycle.value = true;
    try { cycleRuns.value = rows((await window.axios.get('/api/cycle-close-runs')).data); }
    catch (e) { cycleRuns.value = []; }
    finally { loadingCycle.value = false; }
}

// ---- Tab orchestration ---------------------------------------------------------------------
function loadTab() {
    const loaders = {
        invoices: loadInvoices, payments: loadPayments, adjustments: loadAdjustments,
        wallet: () => {}, dunning: loadDunning, tax: loadTax, cycle: loadCycle,
    };
    (loaders[tab.value] ?? (() => {}))();
}
function selectTab(key) { tab.value = key; loadTab(); }
onMounted(() => { loadSummary(); loadTab(); });
</script>

<template>
    <Head :title="t('Billing')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="Billing" :crumbs="[{ label: 'Operations' }, { label: 'Billing' }]" />
        </template>

        <div class="mx-auto max-w-7xl space-y-5">
            <p v-if="notice" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 ring-1 ring-emerald-100">{{ notice }}</p>
            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</p>

            <!-- Summary -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="Open invoices" tone="indigo" :value="stats.open" :loading="loadingSummary" @select="selectTab('invoices')" />
                <StatCard label="Overdue" tone="red" :value="stats.overdue" :loading="loadingSummary" @select="selectTab('invoices')" />
                <StatCard label="Payments today" tone="emerald" :value="stats.paymentsToday" :loading="loadingSummary" @select="selectTab('payments')" />
                <StatCard label="Accounts in dunning" tone="amber" :value="stats.dunning" :loading="loadingSummary" @select="selectTab('dunning')" />
            </div>

            <!-- Tabs -->
            <div class="flex flex-wrap gap-2">
                <button v-for="[key, label] in tabs" :key="key" @click="selectTab(key)"
                    :class="tab === key ? 'bg-op text-white shadow-sm' : 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50'"
                    class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition">{{ t(label) }}</button>
            </div>

            <!-- INVOICES -->
            <Panel v-if="tab === 'invoices'" title="Invoices" subtitle="BIL-02 receivables — click a row for detail, lines and lifecycle">
                <template #actions>
                    <input v-model="invoiceQuery" :placeholder="t('Search number / account / status')"
                        class="w-64 rounded-lg border-gray-200 text-sm focus:border-op focus:ring-op" />
                </template>
                <DataTable :columns="invoiceCols" :rows="filteredInvoices" row-key="invoice_id"
                    :loading="loadingInvoices" empty="No invoices found." @select="openInvoice">
                    <template #cell-total_amount="{ value }">{{ amt(value) }}</template>
                    <template #cell-amount_due="{ value }">{{ amt(value) }}</template>
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-type="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-issue_date="{ value }">{{ when(value) }}</template>
                </DataTable>
            </Panel>

            <!-- PAYMENTS -->
            <Panel v-if="tab === 'payments'" title="Payments" subtitle="BIL-01-PAY-01 — inbound money and allocation">
                <DataTable :columns="paymentCols" :rows="payments" row-key="payment_id"
                    :loading="loadingPayments" empty="No payments recorded." @select="openPayment">
                    <template #cell-method="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-paid_amount="{ value }">{{ amt(value) }}</template>
                    <template #cell-unallocated_amount="{ value }">{{ amt(value) }}</template>
                    <template #cell-received_at="{ value }">{{ when(value) }}</template>
                </DataTable>
            </Panel>

            <!-- ADJUSTMENTS -->
            <div v-if="tab === 'adjustments'" class="space-y-4">
                <Panel title="Propose adjustment" subtitle="BIL-02-ADJ-01 — credit/debit notes are approval-gated (propose → approve → apply)">
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="flex flex-col text-xs text-gray-500">{{ t('Direction') }}
                            <select v-model="newAdjust.direction" class="mt-0.5 rounded-lg border-gray-200 text-sm">
                                <option value="CREDIT">{{ t('CREDIT') }}</option>
                                <option value="DEBIT">{{ t('DEBIT') }}</option>
                            </select>
                        </label>
                        <label class="flex flex-col text-xs text-gray-500">{{ t('Scope') }}
                            <select v-model="newAdjust.scope" class="mt-0.5 rounded-lg border-gray-200 text-sm">
                                <option value="AMOUNT">{{ t('AMOUNT') }}</option>
                                <option value="FULL">{{ t('FULL') }}</option>
                                <option value="LINE">{{ t('LINE') }}</option>
                            </select>
                        </label>
                        <label class="flex flex-col text-xs text-gray-500">{{ t('Amount') }}
                            <input v-model.number="newAdjust.amount" type="number" step="0.01" class="mt-0.5 w-28 rounded-lg border-gray-200 text-sm" />
                        </label>
                        <label class="flex flex-1 flex-col text-xs text-gray-500">{{ t('Invoice (optional)') }}
                            <input v-model="newAdjust.parent_invoice_id" :placeholder="t('inv_…')" class="mt-0.5 rounded-lg border-gray-200 text-sm" />
                        </label>
                        <label class="flex flex-col text-xs text-gray-500">{{ t('Reason') }}
                            <select v-model="newAdjust.reason_code" class="mt-0.5 rounded-lg border-gray-200 text-sm">
                                <option value="">{{ t('Select reason') }}</option>
                                <option v-for="rc in reasonCodes" :key="rc.code" :value="rc.code">{{ rc.code }}</option>
                            </select>
                        </label>
                        <button @click="createAdjustment" class="rounded-lg bg-op px-3.5 py-1.5 text-sm font-medium text-white hover:bg-op-dark">{{ t('Propose') }}</button>
                    </div>
                    <input v-model="newAdjust.justification" :placeholder="t('Justification')" class="mt-2 w-full rounded-lg border-gray-200 text-sm" />
                    <p class="mt-2 text-xs text-amber-600">{{ t('Adjustments require approval before they apply (rule group A).') }}</p>
                </Panel>
                <Panel title="Adjustment requests" subtitle="Proposals and their approval state">
                    <DataTable :columns="adjustCols" :rows="adjustments" row-key="adjustment_id"
                        :loading="loadingAdjust" empty="No adjustments yet.">
                        <template #cell-direction="{ value }"><StatusBadge :status="value" /></template>
                        <template #cell-amount="{ value }">{{ amt(value) }}</template>
                        <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                        <template #row-actions="{ row }">
                            <div class="flex justify-end gap-1">
                                <button v-if="row.status === 'PENDING_APPROVAL'" @click="adjustAction(row, 'approve', 'Adjustment approved')"
                                    class="rounded border border-emerald-200 px-2 py-0.5 text-xs text-emerald-700 hover:bg-emerald-50">{{ t('Approve') }}</button>
                                <button v-if="row.status === 'PENDING_APPROVAL'" @click="adjustAction(row, 'reject', 'Adjustment rejected')"
                                    class="rounded border border-red-200 px-2 py-0.5 text-xs text-red-700 hover:bg-red-50">{{ t('Reject') }}</button>
                            </div>
                        </template>
                    </DataTable>
                </Panel>
            </div>

            <!-- WALLET -->
            <Panel v-if="tab === 'wallet'" title="Wallet" subtitle="BIL-05 — balances are keyed by subscription; look one up to view and top up">
                <div class="flex flex-wrap items-end gap-2">
                    <label class="flex flex-1 flex-col text-xs text-gray-500">{{ t('Subscription ID') }}
                        <input v-model="walletSub" :placeholder="t('sub_…')" class="mt-0.5 rounded-lg border-gray-200 text-sm" @keyup.enter="lookupWallet" />
                    </label>
                    <button @click="lookupWallet" class="rounded-lg bg-op px-3.5 py-1.5 text-sm font-medium text-white hover:bg-op-dark">{{ t('Look up') }}</button>
                </div>

                <div v-if="walletData" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="rounded-xl bg-op-soft p-5 ring-1 ring-op">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('Balance') }}</div>
                        <div class="mt-1 text-4xl font-semibold text-gray-900">{{ amt(walletData.balance) }}</div>
                        <div class="mt-2 flex items-center gap-2 text-xs text-gray-500">
                            <span class="font-mono">{{ walletData.walletCode }}</span>
                            <StatusBadge :status="walletData.status" />
                        </div>
                    </div>
                    <div class="rounded-xl bg-white p-5 ring-1 ring-gray-100">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ t('Top up') }}</div>
                        <div class="mt-2 flex items-end gap-2">
                            <input v-model.number="topupAmount" type="number" step="0.01" :placeholder="t('Amount')" class="w-32 rounded-lg border-gray-200 text-sm" />
                            <button @click="topup" class="rounded-lg bg-emerald-600 px-3.5 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">{{ t('Apply top-up') }}</button>
                        </div>
                    </div>
                </div>
                <p v-else-if="walletErr" class="mt-4 text-sm text-gray-400">{{ t('No wallet found for that subscription.') }}</p>
                <p v-else class="mt-4 text-sm text-gray-400">{{ t('Enter a subscription ID to view its wallet balance.') }}</p>
            </Panel>

            <!-- DUNNING -->
            <Panel v-if="tab === 'dunning'" title="Dunning" subtitle="BIL-04 — accounts on the escalation ladder (read-only)">
                <template #actions>
                    <button class="text-xs font-medium text-op hover:underline" @click="go('dunning.studio')">{{ t('Open Dunning Studio') }}</button>
                </template>
                <DataTable :columns="dunningCols" :rows="dunning" row-key="account_id"
                    :loading="loadingDunning" empty="No accounts in dunning.">
                    <template #cell-current_level="{ row }">
                        <div class="w-56"><StageTracker :stages="ladderFor(row.current_level)" /></div>
                    </template>
                    <template #cell-outstanding_debt_amount="{ value }">{{ amt(value) }}</template>
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-entered_dunning_at="{ value }">{{ when(value) }}</template>
                    <template #cell-review_due_at="{ value }">{{ when(value) }}</template>
                </DataTable>
            </Panel>

            <!-- TAX INVOICES -->
            <div v-if="tab === 'tax'" class="space-y-4">
                <div v-if="taxDash" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <StatCard label="Pending signature" tone="amber" :value="taxDash.pendingSignature ?? '—'" />
                    <StatCard label="Signed" tone="emerald" :value="taxDash.signed ?? '—'" />
                    <StatCard label="Gave up" tone="red" :value="taxDash.gaveUp ?? '—'" />
                    <StatCard label="Cancelled" tone="gray" :value="taxDash.cancelled ?? '—'" />
                </div>
                <Panel title="Tax invoices" subtitle="BIL-02-TAX-01 — fiscalised documents and their signing state">
                    <DataTable :columns="taxCols" :rows="taxInvoices" row-key="tax_invoice_id"
                        :loading="loadingTax" empty="No tax invoices.">
                        <template #cell-total_amount="{ value }">{{ amt(value) }}</template>
                        <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                        <template #cell-created_at="{ value }">{{ when(value) }}</template>
                        <template #row-actions="{ row }">
                            <a :href="taxPdfUrl(row)" target="_blank" rel="noopener"
                                class="rounded border border-gray-200 px-2 py-0.5 text-xs text-op hover:bg-op-soft">{{ t('PDF') }}</a>
                        </template>
                    </DataTable>
                </Panel>
            </div>

            <!-- CYCLE CLOSE -->
            <Panel v-if="tab === 'cycle'" title="Cycle close runs" subtitle="BIL cycle billing — recent close runs and their outcome">
                <DataTable :columns="cycleColumns" :rows="cycleRuns" row-key="run_id" :loading="loadingCycle" empty="No cycle-close runs yet.">
                    <template #cell-started_at="{ value }">{{ when(value) }}</template>
                    <template #cell-completed_at="{ value }">{{ value ? when(value) : t('running…') }}</template>
                </DataTable>
            </Panel>
        </div>

        <!-- Invoice detail drawer -->
        <Drawer v-model:open="invoiceDrawer" :title="invoiceDetail ? invoiceDetail.invoice_id : t('Invoice')" width="max-w-3xl">
            <div v-if="invoiceLoading" class="text-sm text-gray-400">{{ t('Loading…') }}</div>
            <div v-else-if="invoiceDetail" class="space-y-5">
                <div class="flex flex-wrap items-center gap-3">
                    <StatusBadge :status="invoiceDetail.status" />
                    <span class="font-mono text-xs text-gray-500">{{ invoiceDetail.account_id }}</span>
                    <button @click="issueTaxInvoice(invoiceDetail)"
                        class="ml-auto rounded-lg bg-op px-3 py-1 text-xs font-medium text-white hover:bg-op-dark">{{ t('Issue tax invoice') }}</button>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                        <div class="text-xs text-gray-400">{{ t('Subtotal') }}</div>
                        <div class="text-lg font-semibold text-gray-900">{{ amt(invoiceDetail.subtotal_amount) }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                        <div class="text-xs text-gray-400">{{ t('Tax') }}</div>
                        <div class="text-lg font-semibold text-gray-900">{{ amt(invoiceDetail.tax_amount_total) }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                        <div class="text-xs text-gray-400">{{ t('Total') }}</div>
                        <div class="text-lg font-semibold text-gray-900">{{ amt(invoiceDetail.total_amount) }}</div>
                    </div>
                </div>

                <Panel title="Lines">
                    <div v-for="line in (invoiceDetail.summary ?? [])" :key="line.id" class="border-t border-gray-50 py-2 first:border-t-0">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-gray-800">{{ line.description }}</span>
                            <span class="font-semibold text-gray-900">{{ amt(line.amount) }}</span>
                        </div>
                        <div v-for="(d, di) in (line.details ?? [])" :key="di" class="ml-3 flex items-center justify-between text-xs text-gray-500">
                            <span>{{ d.description }} <span v-if="d.quantity" class="text-gray-400">× {{ d.quantity }}</span></span>
                            <span>{{ amt(d.amount) }}</span>
                        </div>
                    </div>
                    <p v-if="!(invoiceDetail.summary ?? []).length" class="text-sm text-gray-400">{{ t('No lines on this invoice.') }}</p>
                </Panel>

                <Panel title="Lifecycle">
                    <Timeline :events="invoiceEvents" />
                </Panel>
            </div>
        </Drawer>

        <!-- Payment allocation drawer -->
        <Drawer v-model:open="paymentDrawer" :title="paymentDetail ? paymentDetail.payment_id : t('Payment')" width="max-w-xl">
            <div v-if="paymentDetail" class="space-y-5">
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                        <div class="text-xs text-gray-400">{{ t('Paid amount') }}</div>
                        <div class="text-lg font-semibold text-gray-900">{{ amt(paymentDetail.paid_amount) }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                        <div class="text-xs text-gray-400">{{ t('Unallocated') }}</div>
                        <div class="text-lg font-semibold text-gray-900">{{ amt(paymentDetail.unallocated_amount) }}</div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <StatusBadge :status="paymentDetail.method" />
                    <span class="text-gray-500">{{ t('Received') }}: {{ when(paymentDetail.received_at) }}</span>
                    <span v-if="paymentDetail.payment_reference" class="font-mono text-xs text-gray-400">{{ paymentDetail.payment_reference }}</span>
                </div>

                <Panel title="Allocation">
                    <div v-if="(paymentDetail.allocations ?? []).length">
                        <div v-for="(a, ai) in paymentDetail.allocations" :key="ai" class="flex items-center justify-between border-t border-gray-50 py-2 text-sm first:border-t-0">
                            <span class="font-mono text-xs text-gray-600">{{ a.invoice_id ?? a.target_invoice_id ?? '—' }}</span>
                            <span class="font-semibold text-gray-900">{{ amt(a.amount ?? a.allocated_amount) }}</span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400">{{ t('Allocation breakdown is not exposed on this payment.') }}</p>
                    <button v-if="Number(paymentDetail.unallocated_amount) > 0" @click="allocateSurplus(paymentDetail)"
                        class="mt-3 rounded-lg border border-op px-3 py-1 text-xs font-medium text-op hover:bg-op-soft">{{ t('Allocate surplus') }}</button>
                </Panel>
            </div>
        </Drawer>
    </AuthenticatedLayout>
</template>
