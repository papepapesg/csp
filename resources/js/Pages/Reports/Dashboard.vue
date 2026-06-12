<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import BarChart from '@/Components/Bss/BarChart.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

// REP-01 reporting surface — reads only from the reporting mart (no operational fan-out).
// Dashboard totals as StatCards, a per-metric time-series BarChart, mart-vs-event-log
// reconciliation status, and CSV export. All copy i18n'd; bars follow operator theme.
const { t, money, dateFmt } = useI18n();

const DASHBOARDS = { 'operations-overview': 'Operations', 'revenue-overview': 'Revenue' };
const code = ref('operations-overview');
const from = ref(new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10));
const to = ref(new Date().toISOString().slice(0, 10));
const loading = ref(true);
const error = ref(null);
const metrics = ref({});
const series = ref([]);
const selectedKey = ref(null);
const recon = ref(null);

const labels = {
    orders_captured: 'Orders captured', orders_completed: 'Orders completed',
    subscriptions_created: 'Subscriptions created', subscriptions_activated: 'Subscriptions activated',
    work_orders_finalized: 'Installs finalized', tickets_created: 'Tickets created', tickets_resolved: 'Tickets resolved',
    invoices_generated: 'Invoices generated', invoices_amount: 'Invoiced', invoices_paid: 'Invoices paid',
    payments_count: 'Payments', payments_amount: 'Collected', wallet_topups_amount: 'Wallet top-ups',
};
const isAmount = (k) => k.endsWith('_amount');
const fmt = (k, v) => (isAmount(k) ? money(v) : Number(v).toLocaleString());
const params = () => ({ from: from.value, to: to.value });

async function load() {
    loading.value = true; error.value = null;
    try {
        const { data } = await window.axios.get(`/api/reports/dashboards/${code.value}`, { params: params() });
        metrics.value = data.metrics ?? {};
        selectedKey.value = Object.keys(metrics.value)[0] ?? null;
        await Promise.all([loadSeries(), loadRecon()]);
    } catch (e) { error.value = e.response?.data?.message ?? t('Failed to load dashboards'); }
    finally { loading.value = false; }
}
async function loadSeries() {
    if (!selectedKey.value) { series.value = []; return; }
    try {
        const { data } = await window.axios.get('/api/reports/metrics', { params: { ...params(), key: selectedKey.value } });
        series.value = (data.items ?? []).map((r) => ({ label: dateFmt(r.metric_date, { month: 'short', day: 'numeric' }), value: Number(r.value) }));
    } catch (e) { series.value = []; }
}
async function loadRecon() {
    try { recon.value = (await window.axios.get('/api/reports/reconcile', { params: params() })).data; }
    catch (e) { recon.value = null; }
}
function exportCsv() {
    const q = new URLSearchParams(params()).toString();
    window.open(`/api/reports/export/${code.value}?${q}`, '_blank');
}
function pick(k) { selectedKey.value = k; loadSeries(); }
const seriesFmt = computed(() => (v) => (selectedKey.value && isAmount(selectedKey.value) ? money(v) : v));

onMounted(load);
</script>

<template>
    <Head :title="t('Reports')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="Reports" :crumbs="[{ label: 'Insight' }, { label: 'Reports' }]">
                <template #actions>
                    <select v-model="code" @change="load" class="rounded-lg border-gray-200 text-sm">
                        <option v-for="(label, c) in DASHBOARDS" :key="c" :value="c">{{ t(label) }}</option>
                    </select>
                    <input type="date" v-model="from" @change="load" class="rounded-lg border-gray-200 text-sm" />
                    <input type="date" v-model="to" @change="load" class="rounded-lg border-gray-200 text-sm" />
                    <button @click="exportCsv" class="rounded-lg bg-op px-3 py-1.5 text-sm font-medium text-white hover:bg-op-dark">{{ t('Export CSV') }}</button>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-5">
            <p v-if="error" class="rounded bg-red-50 p-3 text-sm text-red-600">{{ error }}</p>

            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4">
                <StatCard v-for="(v, k) in metrics" :key="k" :label="labels[k] ?? k" :value="fmt(k, v)"
                    :tone="isAmount(k) ? 'emerald' : 'indigo'" :loading="loading"
                    :sub="selectedKey === k ? t('charted below') : ''" @select="pick(k)" />
            </div>

            <Panel :title="t(labels[selectedKey] ?? selectedKey ?? 'Trend')" subtitle="Daily series over the selected range">
                <BarChart :series="series" :format="seriesFmt" :height="180" />
            </Panel>

            <Panel title="Mart integrity" subtitle="Reconciliation of the mart against the committed event log">
                <template #actions>
                    <StatusBadge v-if="recon" :status="recon.inSync ? 'IN_SYNC' : 'DISCREPANCY'"
                        :map="{ IN_SYNC: 'bg-emerald-100 text-emerald-700 ring-emerald-600/20', DISCREPANCY: 'bg-red-100 text-red-700 ring-red-600/20' }" />
                </template>
                <div v-if="recon" class="text-sm text-gray-600">
                    <p>{{ t('Checked') }}: {{ recon.checked }} · {{ recon.inSync ? t('Mart matches the event log.') : t('Discrepancies found:') }}</p>
                    <ul v-if="!recon.inSync" class="mt-2 list-disc pl-5 text-xs text-red-600">
                        <li v-for="(d, i) in recon.discrepancies" :key="i">{{ JSON.stringify(d) }}</li>
                    </ul>
                </div>
                <p v-else class="text-sm text-gray-400">{{ t('Reconciliation not available.') }}</p>
            </Panel>

            <p class="text-xs text-gray-400">{{ t('Metrics are projected from committed domain events via the transactional outbox.') }}</p>
        </div>
    </AuthenticatedLayout>
</template>
