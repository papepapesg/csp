<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import StageTracker from '@/Components/Bss/StageTracker.vue';
import Timeline from '@/Components/Bss/Timeline.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import Drawer from '@/Components/Bss/Drawer.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

const { t, money, dateFmt } = useI18n();

// Customer 360 — the "single pane of glass" any internal agent opens before any action
// (ILM-CFG-01). Identity + KYC on the Customer; everything operational anchored on the
// Accounts; cross-module panels (subscriptions, invoices, tickets, interactions) read from
// each OWNING module via the resilient /overview composition — this page holds no business
// state. Every panel resolves independently, so a failing module blanks only its own card.
const props = defineProps({ customerId: String });

const customer = ref(null);
const accounts = ref([]);
const subscriptions = ref([]);
const billing = ref({ balanceDue: 0, recentInvoices: [] });
const tickets = ref([]);
const notes = ref([]);
const interactions = ref([]);
const newNote = ref('');
const error = ref(null);
const loading = ref(true);
const unavailable = ref({});           // panel name -> true when its module read degraded
const selectedSub = ref(null);         // subscription drawer

async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(`/api/customers/${props.customerId}/overview`);
        const p = data.panels ?? {};
        const ok = (name, fallback) => {
            const avail = !!p[name]?.available;
            unavailable.value[name] = !avail;
            return avail ? (p[name].data ?? fallback) : fallback;
        };
        customer.value = ok('profile', null);
        accounts.value = ok('accounts', []);
        subscriptions.value = ok('subscriptions', []);
        billing.value = ok('billing', { balanceDue: 0, recentInvoices: [] });
        tickets.value = ok('tickets', []);
        notes.value = ok('notes', []);
        interactions.value = ok('interactions', []);
    } catch (e) {
        error.value = e.response?.data?.message ?? t('Failed to load customer');
    } finally {
        loading.value = false;
    }
}

async function addNote() {
    if (!newNote.value.trim()) return;
    await window.axios.post(`/api/customers/${props.customerId}/notes`, { body: newNote.value });
    newNote.value = '';
    const n = await window.axios.get(`/api/customers/${props.customerId}/notes`);
    notes.value = n.data.items ?? n.data.data ?? [];
}

// --- formatting (operator locale/currency via the i18n foundation) -----------------------
const fmtDate = (iso) => (iso ? dateFmt(iso, { dateStyle: 'medium' }) : '');
const fmtDateTime = (iso) => (iso ? dateFmt(iso) : '');

// --- derived metrics ---------------------------------------------------------------------
const activeSubs = computed(() => subscriptions.value.filter((s) => s.status === 'ACTIVE').length);
const openTickets = computed(() => tickets.value.filter((t) => !['RESOLVED', 'CLOSED'].includes(t.status)).length);
const balanceTone = computed(() => (Number(billing.value.balanceDue) > 0 ? 'red' : 'emerald'));

// Onboarding lifecycle, derived purely from real state (no invented journey): Registered →
// KYC → Account opened → Service active. This visualises where the customer sits in the
// process the workshop describes, straight from the data already on the screen.
const lifecycle = computed(() => {
    const c = customer.value;
    const hasAccount = accounts.value.length > 0;
    const hasActiveSub = activeSubs.value > 0;
    const hasAnySub = subscriptions.value.length > 0;
    const kyc = c?.kycStatus;
    const kycState = kyc === 'APPROVED' ? 'done'
        : kyc === 'REJECTED' ? 'failed'
        : (kyc === 'PENDING' || kyc === 'L1_APPROVED') ? 'current' : 'pending';
    return [
        { key: 'reg', label: 'Registered', state: c ? 'done' : 'pending', at: fmtDate(c?.createdAt) },
        { key: 'kyc', label: 'KYC verified', state: kycState },
        { key: 'acct', label: 'Account opened', state: hasAccount ? 'done' : 'pending' },
        { key: 'svc', label: 'Service active', state: hasActiveSub ? 'done' : hasAnySub ? 'current' : 'pending' },
    ];
});

// Unified history — interactions, tickets and notes merged into one chronological spine
// ("view history using relevant visual components"). Newest first.
const history = computed(() => {
    const ev = [];
    for (const i of interactions.value) ev.push({ at: i.at, _ts: i.at, tone: 'blue', title: i.reason ?? t('Interaction'), subtitle: i.agent ? t('by :a', { a: i.agent }) : '' });
    for (const tk of tickets.value) ev.push({ at: tk.at, _ts: tk.at, tone: tk.status === 'RESOLVED' ? 'green' : 'amber', title: t('Ticket: :s', { s: tk.subject }), subtitle: `${tk.status} · ${tk.priority ?? ''}` });
    for (const n of notes.value) ev.push({ at: n.at, _ts: n.at, tone: 'gray', title: t('Note'), subtitle: n.body });
    return ev
        .filter((e) => e._ts)
        .sort((a, b) => new Date(b._ts) - new Date(a._ts))
        .map((e) => ({ ...e, at: fmtDateTime(e.at) }));
});

const subColumns = [
    { key: 'subscriptionId', label: 'Subscription' },
    { key: 'package', label: 'Package' },
    { key: 'billingMode', label: 'Billing' },
    { key: 'homepassId', label: 'HomePass' },
    { key: 'status', label: 'Status' },
];
const invoiceColumns = [
    { key: 'number', label: 'Invoice' },
    { key: 'due', label: 'Amount due', align: 'right' },
    { key: 'status', label: 'Status' },
];
const ticketColumns = [
    { key: 'subject', label: 'Subject' },
    { key: 'priority', label: 'Priority' },
    { key: 'status', label: 'Status' },
];

onMounted(load);
</script>

<template>
    <Head :title="customer?.name ?? t('Customer')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader :title="customer?.name ?? '…'" :crumbs="[{ label: 'CRM' }, { label: 'Customers', href: route('customers.index') }, { label: customer?.name ?? '…' }]">
                <template #actions>
                    <StatusBadge v-if="customer?.type" :status="customer.type === 'RES' ? 'Residential' : 'Commercial'" :map="{ Residential: 'bg-op-soft text-op ring-op', Commercial: 'bg-indigo-100 text-indigo-700 ring-indigo-600/20' }" />
                    <span v-if="customer" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                        :class="customer.kycStatus === 'APPROVED' ? 'bg-emerald-100 text-emerald-700 ring-emerald-600/20' : customer.kycStatus === 'REJECTED' ? 'bg-red-100 text-red-700 ring-red-600/20' : 'bg-amber-100 text-amber-700 ring-amber-600/20'">
                        {{ t('KYC') }} · {{ customer.kycStatus }}
                    </span>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <p v-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</p>

            <!-- Onboarding lifecycle -->
            <Panel :title="t('Onboarding journey')" :subtitle="t('Where this customer sits in the lifecycle')">
                <StageTracker :stages="lifecycle" />
            </Panel>

            <!-- Key metrics -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard :label="t('Balance due')" :value="money(billing.balanceDue)" :tone="balanceTone" :sub="customer?.kycStatus ? '' : ''" :loading="loading" />
                <StatCard :label="t('Accounts')" :value="accounts.length" tone="indigo" :loading="loading" />
                <StatCard :label="t('Active subscriptions')" :value="activeSubs" tone="emerald" :sub="t(':n total', { n: subscriptions.length })" :loading="loading" />
                <StatCard :label="t('Open tickets')" :value="openTickets" :tone="openTickets ? 'amber' : 'gray'" :loading="loading" />
            </div>

            <div class="grid grid-cols-12 gap-5">
                <!-- Identity -->
                <div class="col-span-12 lg:col-span-4">
                    <Panel :title="t('Identity (legal entity)')">
                        <dl v-if="customer" class="space-y-2 text-sm">
                            <div class="flex justify-between gap-2"><dt class="text-gray-500">{{ t('Customer ID') }}</dt><dd class="font-mono text-xs text-gray-700">{{ customer.customerId }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-gray-500">{{ t('Type') }}</dt><dd>{{ customer.type === 'RES' ? t('Residential') : t('Commercial') }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-gray-500">{{ t('MSISDN') }}</dt><dd>{{ customer.msisdn ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-gray-500">{{ t('Email') }}</dt><dd>{{ customer.email ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-gray-500">{{ t('Language') }}</dt><dd>{{ customer.preferredLanguage ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-gray-500">{{ t('Customer since') }}</dt><dd>{{ fmtDate(customer.createdAt) || '—' }}</dd></div>
                        </dl>
                        <p v-else-if="!loading" class="text-sm text-gray-400">{{ t('Profile unavailable.') }}</p>
                    </Panel>
                </div>

                <!-- Accounts (operational anchor) -->
                <div class="col-span-12 lg:col-span-8">
                    <Panel :title="t('Accounts (operational anchor)')" :subtitle="t('Status, sub-status and flags drive every operation')">
                        <div v-if="accounts.length" class="space-y-3">
                            <div v-for="a in accounts" :key="a.accountId" class="rounded-lg border border-gray-100 p-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-xs text-gray-700">{{ a.accountNumber }}</span>
                                    <StatusBadge :status="a.status" />
                                    <span v-if="a.subStatus" class="text-xs text-gray-400">{{ a.subStatus }}</span>
                                    <span v-for="f in a.flags ?? []" :key="f" class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-600 ring-1 ring-red-100">{{ f }}</span>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                    <span v-if="a.serviceAddress">📍 {{ a.serviceAddress }}</span>
                                    <span v-if="a.homepassId">{{ t('HomePass') }}: {{ a.homepassId }}</span>
                                    <span v-if="a.installDate">{{ t('Installed') }} {{ fmtDate(a.installDate) }}</span>
                                    <span v-if="a.startBillDate">{{ t('Billing from') }} {{ fmtDate(a.startBillDate) }}</span>
                                </div>
                                <div v-if="a.attentionBanner" class="mt-2 rounded bg-amber-50 px-2 py-1 text-xs text-amber-800 ring-1 ring-amber-100">⚠ {{ a.attentionBanner }}</div>
                            </div>
                        </div>
                        <p v-else-if="!loading" class="text-sm text-gray-400">{{ t('No accounts.') }}</p>
                    </Panel>
                </div>
            </div>

            <!-- Subscriptions -->
            <Panel :title="t('Subscriptions (SUB-LM)')" :subtitle="t('Click a row for details')">
                <DataTable :columns="subColumns" :rows="subscriptions" row-key="subscriptionId" :loading="loading" empty="No subscriptions." @select="selectedSub = $event">
                    <template #cell-subscriptionId="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-homepassId="{ value }"><span class="text-xs text-gray-500">{{ value ?? '—' }}</span></template>
                </DataTable>
            </Panel>

            <div class="grid grid-cols-12 gap-5">
                <!-- Invoices -->
                <div class="col-span-12 lg:col-span-6">
                    <Panel :title="t('Recent invoices (BIL)')">
                        <template #actions>
                            <span class="text-xs text-gray-500">{{ t('Balance due') }}: <strong :class="Number(billing.balanceDue) > 0 ? 'text-red-600' : 'text-emerald-600'">{{ money(billing.balanceDue) }}</strong></span>
                        </template>
                        <DataTable :columns="invoiceColumns" :rows="billing.recentInvoices" row-key="number" :loading="loading" empty="No invoices.">
                            <template #cell-number="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                            <template #cell-due="{ value }"><span class="tabular-nums">{{ money(value) }}</span></template>
                            <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                        </DataTable>
                    </Panel>
                </div>
                <!-- Tickets -->
                <div class="col-span-12 lg:col-span-6">
                    <Panel :title="t('Tickets (TCK)')">
                        <DataTable :columns="ticketColumns" :rows="tickets" row-key="number" :loading="loading" empty="No tickets.">
                            <template #cell-subject="{ row }"><span class="truncate">{{ row.subject }}</span></template>
                            <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                        </DataTable>
                    </Panel>
                </div>
            </div>

            <div class="grid grid-cols-12 gap-5">
                <!-- Notes -->
                <div class="col-span-12 lg:col-span-5">
                    <Panel :title="t('Notes')">
                        <div class="mb-3 flex gap-2">
                            <input v-model="newNote" @keyup.enter="addNote" :placeholder="t('Add a note…')" class="flex-1 rounded-md border-gray-300 text-sm" />
                            <button @click="addNote" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ t('Add') }}</button>
                        </div>
                        <ul class="space-y-2">
                            <li v-for="(n, i) in notes" :key="i" class="rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700">
                                {{ n.body }}
                                <div class="mt-0.5 text-[11px] text-gray-400">{{ fmtDateTime(n.at) }}</div>
                            </li>
                            <li v-if="!notes.length" class="text-sm text-gray-400">{{ t('No notes yet.') }}</li>
                        </ul>
                    </Panel>
                </div>
                <!-- History -->
                <div class="col-span-12 lg:col-span-7">
                    <Panel :title="t('History')" :subtitle="t('Interactions, tickets & notes')">
                        <Timeline :events="history" />
                    </Panel>
                </div>
            </div>
        </div>

        <!-- Subscription detail drawer -->
        <Drawer :open="!!selectedSub" :title="t('Subscription')" @update:open="selectedSub = null">
            <div v-if="selectedSub" class="space-y-4">
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-xs text-gray-700">{{ selectedSub.subscriptionId }}</span>
                        <StatusBadge :status="selectedSub.status" />
                    </div>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">{{ t('Package') }}</dt><dd>{{ selectedSub.package ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">{{ t('Billing') }}</dt><dd>{{ selectedSub.billingMode ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">{{ t('HomePass') }}</dt><dd>{{ selectedSub.homepassId ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">{{ t('Started') }}</dt><dd>{{ fmtDate(selectedSub.createdAt) || '—' }}</dd></div>
                    </dl>
                </div>
            </div>
        </Drawer>
    </AuthenticatedLayout>
</template>
