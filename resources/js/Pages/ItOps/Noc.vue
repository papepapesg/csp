<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';

// NOC Console: one pane of glass over everything running — service heartbeats with
// start/stop/restart, workflow incidents, provisioning mismatches, outbox backlog,
// SLA breaches — plus the END-TO-END TRACE: every record carries a correlation id,
// so one key (subscription / order / operation / correlation id) reconstructs the
// whole journey across events, workflows, provisioning, notifications and logs.
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

const sourceColor = (s) => ({ EVENT: 'bg-blue-100 text-blue-700', WORKFLOW: 'bg-indigo-100 text-indigo-700', TASK: 'bg-purple-100 text-purple-700', PROVISIONING: 'bg-amber-100 text-amber-700', NOTIFICATION: 'bg-green-100 text-green-700', LOG: 'bg-gray-100 text-gray-600' }[s] ?? 'bg-gray-100');

onMounted(() => { loadOverview(); loadLogs(); loadSla(); timer = setInterval(loadOverview, 15000); });
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <Head title="NOC Console" />
    <AuthenticatedLayout>
        <template #header><h2 class="font-semibold text-xl text-gray-800">NOC Console</h2></template>

        <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4 flex gap-2 items-center">
                <button v-for="t in ['overview', 'trace', 'logs', 'sla']" :key="t" @click="tab = t; t === 'logs' && loadLogs(); t === 'sla' && loadSla()"
                    :class="tab === t ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-1 rounded border text-sm capitalize">{{ t }}</button>
                <div class="ml-auto flex gap-1">
                    <input v-model="trace.key" @keyup.enter="runTrace()" placeholder="Trace: sub_…, ford_…, op_…, correlation id"
                        class="border rounded px-2 py-1 text-sm w-72" />
                    <button @click="runTrace()" class="px-3 py-1 bg-indigo-600 text-white rounded text-sm">Trace</button>
                </div>
            </div>

            <!-- Overview -->
            <div v-if="tab === 'overview' && overview" class="space-y-4">
                <div class="grid grid-cols-5 gap-3">
                    <div class="bg-white rounded shadow p-3"><div class="text-xs text-gray-500">Running flows</div><div class="text-2xl font-bold">{{ overview.runningInstances }}</div></div>
                    <div class="bg-white rounded shadow p-3" :class="overview.workflowIncidents ? 'ring-2 ring-red-300' : ''"><div class="text-xs text-gray-500">Workflow incidents</div><div class="text-2xl font-bold" :class="overview.workflowIncidents ? 'text-red-600' : ''">{{ overview.workflowIncidents }}</div></div>
                    <div class="bg-white rounded shadow p-3" :class="overview.provisioningMismatches ? 'ring-2 ring-amber-300' : ''"><div class="text-xs text-gray-500">Provisioning mismatches</div><div class="text-2xl font-bold" :class="overview.provisioningMismatches ? 'text-amber-600' : ''">{{ overview.provisioningMismatches }}</div></div>
                    <div class="bg-white rounded shadow p-3"><div class="text-xs text-gray-500">Outbox backlog</div><div class="text-2xl font-bold">{{ overview.outboxBacklog }}</div></div>
                    <div class="bg-white rounded shadow p-3" :class="overview.slaOverdueTickets ? 'ring-2 ring-red-300' : ''"><div class="text-xs text-gray-500">SLA-overdue tickets</div><div class="text-2xl font-bold" :class="overview.slaOverdueTickets ? 'text-red-600' : ''">{{ overview.slaOverdueTickets }}</div></div>
                </div>

                <div class="bg-white rounded shadow p-4">
                    <div class="font-semibold mb-2">Platform services</div>
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500 uppercase"><th class="py-1">Service</th><th>Status</th><th>Last seen</th><th>Metrics</th><th class="text-right">Control</th></tr></thead>
                        <tbody>
                            <tr v-for="s in overview.services" :key="s.service" class="border-t">
                                <td class="py-1.5 font-mono text-xs">{{ s.service }}</td>
                                <td><span class="px-1.5 py-0.5 rounded text-xs" :class="s.status === 'UP' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">{{ s.status }}</span></td>
                                <td class="text-xs text-gray-500">{{ s.last_seen_at }}</td>
                                <td class="text-xs font-mono text-gray-500">{{ JSON.stringify(s.metrics ?? {}) }}</td>
                                <td class="text-right space-x-1">
                                    <button v-if="s.status === 'UP'" @click="service(s.service, 'stop')" class="px-2 py-0.5 bg-red-500 text-white rounded text-xs">Stop</button>
                                    <button v-else @click="service(s.service, 'start')" class="px-2 py-0.5 bg-green-600 text-white rounded text-xs">Start</button>
                                    <button @click="restart(s.service)" class="px-2 py-0.5 bg-gray-200 rounded text-xs">Restart</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Trace -->
            <div v-else-if="tab === 'trace'" class="bg-white rounded shadow p-4">
                <div v-if="trace.loading" class="text-sm text-gray-500">Tracing…</div>
                <div v-else-if="!trace.result" class="text-sm text-gray-500">Enter a subscription / order / operation / correlation id above and hit Trace.</div>
                <div v-else>
                    <div class="mb-2 text-sm">
                        <span class="font-semibold">{{ trace.result.key }}</span>
                        <span class="text-xs text-gray-400 ml-2">correlations: {{ trace.result.correlationIds.join(', ') }}</span>
                        <span class="text-xs text-gray-400 ml-2">{{ trace.result.timeline.length }} steps</span>
                    </div>
                    <div class="border-l-2 border-indigo-200 pl-4 space-y-1.5">
                        <div v-for="(t, i) in trace.result.timeline" :key="i" class="text-sm flex gap-2 items-baseline">
                            <span class="text-xs text-gray-400 w-40 shrink-0">{{ t.at }}</span>
                            <span class="px-1.5 py-0.5 rounded text-xs shrink-0" :class="sourceColor(t.source)">{{ t.source }}</span>
                            <span class="font-medium">{{ t.label }}</span>
                            <span class="text-xs text-gray-400">{{ t.detail }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Logs -->
            <div v-else-if="tab === 'logs'" class="bg-white rounded shadow p-4">
                <div class="flex gap-2 mb-2">
                    <select v-model="logFilter.level" @change="loadLogs" class="border rounded px-2 py-1 text-sm">
                        <option value="">all levels</option><option>error</option><option>warning</option><option>info</option><option>debug</option>
                    </select>
                    <input v-model="logFilter.channel" @keyup.enter="loadLogs" placeholder="channel" class="border rounded px-2 py-1 text-sm" />
                </div>
                <div class="font-mono text-xs space-y-0.5 max-h-[32rem] overflow-auto">
                    <div v-for="l in logs" :key="l.id" class="flex gap-2">
                        <span class="text-gray-400 w-40 shrink-0">{{ l.logged_at }}</span>
                        <span class="w-14 shrink-0 font-semibold" :class="{ error: 'text-red-600', critical: 'text-red-700', warning: 'text-amber-600' }[l.level] ?? 'text-gray-500'">{{ l.level }}</span>
                        <span class="flex-1">{{ l.message }}</span>
                        <button v-if="l.correlation_id" @click="runTrace(l.correlation_id)" class="text-indigo-500 underline shrink-0">trace</button>
                    </div>
                </div>
            </div>

            <!-- SLA -->
            <div v-else-if="tab === 'sla'" class="bg-white rounded shadow p-4">
                <div class="font-semibold mb-2">Tickets breaching SLA</div>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500 uppercase"><th class="py-1">Ticket</th><th>Subject</th><th>Priority</th><th>Status</th><th>Queue</th><th>Due</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="t in sla" :key="t.ticket_id" class="border-t">
                            <td class="py-1 font-mono text-xs">{{ t.ticket_id }}</td>
                            <td>{{ t.subject }}</td>
                            <td>{{ t.priority }}</td>
                            <td>{{ t.status }}</td>
                            <td class="text-xs">{{ t.queue ?? '—' }}</td>
                            <td class="text-xs text-red-600">{{ t.sla_due_at }}</td>
                            <td><button @click="runTrace(t.ticket_id)" class="text-indigo-500 underline text-xs">trace</button></td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!sla.length" class="text-sm text-green-600">No SLA breaches. 🎉</div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
