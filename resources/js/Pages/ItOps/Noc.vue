<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import Timeline from '@/Components/Bss/Timeline.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useI18n } from '@/i18n';

const { t, dateFmt } = useI18n();

// FE-APP-01 §15 NOC console — one pane of glass over everything running: service heartbeats
// with start/stop/restart, workflow incidents, provisioning mismatches, outbox backlog, SLA
// breaches — plus the END-TO-END TRACE: every record carries a correlation id, so one key
// (subscription / order / operation / correlation id) reconstructs the whole journey across
// events, workflows, provisioning, notifications and logs, rendered as a chronological timeline.
const tab = ref('overview'); // overview | trace | logs | sla
const overview = ref(null);
const logs = ref([]);
const logFilter = ref({ level: '', channel: '' });
const sla = ref([]);
const trace = ref({ key: '', result: null, loading: false });
let timer = null;

async function loadOverview() {
    const { data } = await window.axios.get('/api/noc/overview');
    overview.value = data;
}
async function loadLogs() {
    const { data } = await window.axios.get('/api/itops/logs', { params: { level: logFilter.value.level || undefined, channel: logFilter.value.channel || undefined, size: 100 } });
    logs.value = data.items ?? data.content ?? [];
}
async function loadSla() {
    const { data } = await window.axios.get('/api/noc/sla-overdue');
    sla.value = data.items ?? [];
}
async function runTrace(key) {
    trace.value.key = key ?? trace.value.key;
    if (!trace.value.key) return;
    trace.value.loading = true;
    try {
        const { data } = await window.axios.get('/api/noc/trace', { params: { key: trace.value.key } });
        trace.value.result = data;
        tab.value = 'trace';
    } finally { trace.value.loading = false; }
}
async function service(s, action) {
    await window.axios.post(`/api/noc/services/${s}/${action}`);
    await loadOverview();
}
async function restart(s) {
    await window.axios.post(`/api/itops/services/${s}/restart`);
    await loadOverview();
}

function selectTab(tb) {
    tab.value = tb;
    if (tb === 'logs') loadLogs();
    if (tb === 'sla') loadSla();
}

const TABS = [
    ['overview', 'Overview'],
    ['trace', 'Trace'],
    ['logs', 'Logs'],
    ['sla', 'SLA'],
];

// Trace source → timeline dot tone (semantic, not brand).
const sourceTone = { EVENT: 'blue', WORKFLOW: 'indigo', TASK: 'indigo', PROVISIONING: 'amber', NOTIFICATION: 'green', LOG: 'gray' };

// Map each traced step (event / instance / task / command / log / notification) into a
// Timeline entry: the source + label become the title, the detail the subtitle, the
// formatted timestamp the `at`. This is the end-to-end trace viewed visually.
const traceEvents = computed(() => (trace.value.result?.timeline ?? []).map((step) => ({
    title: `${step.source} · ${step.label}`,
    subtitle: step.detail,
    at: dateFmt(step.at),
    tone: sourceTone[step.source] ?? 'gray',
})));

const serviceColumns = [
    { key: 'service', label: 'Service', class: 'font-mono text-xs' },
    { key: 'status', label: 'Status' },
    { key: 'last_seen_at', label: 'Last seen' },
    { key: 'metrics', label: 'Metrics' },
];

const slaColumns = [
    { key: 'ticket_id', label: 'Ticket', class: 'font-mono text-xs' },
    { key: 'subject', label: 'Subject' },
    { key: 'priority', label: 'Priority' },
    { key: 'status', label: 'Status' },
    { key: 'queue', label: 'Queue' },
    { key: 'sla_due_at', label: 'Due' },
];

const levelTone = (lvl) => ({ error: 'text-red-600', critical: 'text-red-700', warning: 'text-amber-600' }[lvl] ?? 'text-gray-500');

onMounted(() => { loadOverview(); loadLogs(); loadSla(); timer = setInterval(loadOverview, 15000); });
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <Head :title="t('NOC')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="NOC" :crumbs="[{ label: 'Admin' }, { label: 'NOC' }]">
                <template #actions>
                    <input v-model="trace.key" @keyup.enter="runTrace()"
                        :placeholder="t('Trace: sub_…, ford_…, op_…, correlation id')"
                        class="w-72 rounded-lg border-gray-300 text-sm focus:border-op focus:ring-op" />
                    <button type="button" @click="runTrace()"
                        class="rounded-lg bg-op px-3 py-2 text-sm font-medium text-white transition hover:opacity-90">
                        {{ t('Trace') }}
                    </button>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-4 px-4 py-6 sm:px-6 lg:px-8">
            <!-- Tabs -->
            <div class="flex flex-wrap items-center gap-2">
                <button v-for="[key, label] in TABS" :key="key" type="button" @click="selectTab(key)"
                    class="rounded-lg border px-3 py-1.5 text-sm font-medium transition"
                    :class="tab === key ? 'border-transparent bg-op text-white' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'">
                    {{ t(label) }}
                </button>
            </div>

            <!-- Overview -->
            <template v-if="tab === 'overview'">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <StatCard label="Running flows" :value="overview?.runningInstances ?? 0" tone="indigo"
                        :loading="!overview" @select="selectTab('trace')" />
                    <StatCard label="Workflow incidents" :value="overview?.workflowIncidents ?? 0"
                        :tone="overview?.workflowIncidents ? 'red' : 'gray'" :loading="!overview" />
                    <StatCard label="Provisioning mismatches" :value="overview?.provisioningMismatches ?? 0"
                        :tone="overview?.provisioningMismatches ? 'amber' : 'gray'" :loading="!overview" />
                    <StatCard label="Outbox backlog" :value="overview?.outboxBacklog ?? 0" tone="cyan"
                        :loading="!overview" />
                    <StatCard label="SLA-overdue tickets" :value="overview?.slaOverdueTickets ?? 0"
                        :tone="overview?.slaOverdueTickets ? 'red' : 'gray'" :loading="!overview"
                        @select="selectTab('sla')" />
                </div>

                <Panel title="Platform services" subtitle="Heartbeats with start / stop / restart control">
                    <DataTable :columns="serviceColumns" :rows="overview?.services ?? []" rowKey="service"
                        :loading="!overview" empty="No services reporting.">
                        <template #cell-status="{ row }">
                            <StatusBadge :status="row.status === 'UP' ? 'ACTIVE' : 'FAILED'"
                                :map="{ ACTIVE: 'bg-emerald-100 text-emerald-700 ring-emerald-600/20', FAILED: 'bg-red-100 text-red-700 ring-red-600/20' }" />
                        </template>
                        <template #cell-last_seen_at="{ row }">
                            <span class="text-xs text-gray-500">{{ dateFmt(row.last_seen_at) }}</span>
                        </template>
                        <template #cell-metrics="{ row }">
                            <span class="font-mono text-xs text-gray-500">{{ JSON.stringify(row.metrics ?? {}) }}</span>
                        </template>
                        <template #row-actions="{ row }">
                            <button v-if="row.status === 'UP'" type="button" @click="service(row.service, 'stop')"
                                class="rounded bg-red-500 px-2 py-0.5 text-xs font-medium text-white">{{ t('Stop') }}</button>
                            <button v-else type="button" @click="service(row.service, 'start')"
                                class="rounded bg-emerald-600 px-2 py-0.5 text-xs font-medium text-white">{{ t('Start') }}</button>
                            <button type="button" @click="restart(row.service)"
                                class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ t('Restart') }}</button>
                        </template>
                    </DataTable>
                </Panel>
            </template>

            <!-- Trace: the end-to-end correlation-id journey, rendered chronologically -->
            <Panel v-else-if="tab === 'trace'" title="End-to-end trace"
                subtitle="One key reconstructs the journey across events, workflows, provisioning, notifications and logs">
                <template v-if="trace.result" #actions>
                    <span class="font-mono text-xs text-gray-600">{{ trace.result.key }}</span>
                    <span class="rounded-full bg-op-soft px-2 py-0.5 text-xs font-medium text-op">
                        {{ trace.result.timeline.length }} {{ t('steps') }}
                    </span>
                </template>

                <div v-if="trace.loading" class="py-6 text-center text-sm text-gray-400">{{ t('Tracing…') }}</div>
                <div v-else-if="!trace.result" class="py-6 text-center text-sm text-gray-400">
                    {{ t('Enter a subscription / order / operation / correlation id above and hit Trace.') }}
                </div>
                <div v-else class="space-y-3">
                    <p v-if="trace.result.correlationIds?.length" class="text-xs text-gray-400">
                        {{ t('correlations:') }} {{ trace.result.correlationIds.join(', ') }}
                    </p>
                    <Timeline :events="traceEvents" />
                </div>
            </Panel>

            <!-- Logs -->
            <Panel v-else-if="tab === 'logs'" title="Platform logs"
                subtitle="Filter by level / channel; trace any line by its correlation id">
                <template #actions>
                    <select v-model="logFilter.level" @change="loadLogs"
                        class="rounded-lg border-gray-300 text-sm focus:border-op focus:ring-op">
                        <option value="">{{ t('all levels') }}</option>
                        <option value="error">{{ t('error') }}</option>
                        <option value="warning">{{ t('warning') }}</option>
                        <option value="info">{{ t('info') }}</option>
                        <option value="debug">{{ t('debug') }}</option>
                    </select>
                    <input v-model="logFilter.channel" @keyup.enter="loadLogs" :placeholder="t('channel')"
                        class="w-40 rounded-lg border-gray-300 text-sm focus:border-op focus:ring-op" />
                </template>

                <div class="max-h-[32rem] space-y-0.5 overflow-auto font-mono text-xs">
                    <div v-for="l in logs" :key="l.id" class="flex items-baseline gap-2 rounded px-1 py-0.5 hover:bg-gray-50">
                        <span class="w-40 shrink-0 text-gray-400">{{ dateFmt(l.logged_at) }}</span>
                        <span class="w-14 shrink-0 font-semibold" :class="levelTone(l.level)">{{ l.level }}</span>
                        <span class="flex-1">{{ l.message }}</span>
                        <button v-if="l.correlation_id" type="button" @click="runTrace(l.correlation_id)"
                            class="shrink-0 text-op underline">{{ t('trace') }}</button>
                    </div>
                    <div v-if="!logs.length" class="py-6 text-center text-sm text-gray-400">{{ t('No log entries.') }}</div>
                </div>
            </Panel>

            <!-- SLA -->
            <Panel v-else-if="tab === 'sla'" title="Tickets breaching SLA">
                <div v-if="!sla.length" class="py-6 text-center text-sm text-emerald-600">{{ t('No SLA breaches.') }}</div>
                <DataTable v-else :columns="slaColumns" :rows="sla" rowKey="ticket_id" empty="No SLA breaches.">
                    <template #cell-status="{ row }">
                        <StatusBadge :status="row.status" />
                    </template>
                    <template #cell-queue="{ row }">
                        <span class="text-xs">{{ row.queue ?? '—' }}</span>
                    </template>
                    <template #cell-sla_due_at="{ row }">
                        <span class="text-xs text-red-600">{{ dateFmt(row.sla_due_at) }}</span>
                    </template>
                    <template #row-actions="{ row }">
                        <button type="button" @click="runTrace(row.ticket_id)" class="text-xs text-op underline">{{ t('trace') }}</button>
                    </template>
                </DataTable>
            </Panel>
        </div>
    </AuthenticatedLayout>
</template>
