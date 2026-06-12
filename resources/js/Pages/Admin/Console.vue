<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import Drawer from '@/Components/Bss/Drawer.vue';
import Timeline from '@/Components/Bss/Timeline.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

// FE-APP-01 §15 Admin backoffice — the cross-cutting platform admin surface over the
// existing foundation endpoints: health/runtime config, outbox/inbox backlog monitor,
// cache admin + stats, RBAC change audit search, operator config (read-only), and a
// workflow incident summary. Every panel reads a REAL endpoint; anything without a
// backing API renders a graceful "not available" note rather than 404ing. Resilient:
// per-panel try/catch so a single down endpoint never blanks the console.
const { t, dateFmt } = useI18n();
const page = usePage();

// The app helpers return either {items:[...]} (paginated) or {data:[...]} or a bare array —
// normalize every list shape through one helper (mirrors the other backoffice surfaces).
const rows = (d, ...keys) => {
    if (Array.isArray(d)) return d;
    for (const k of keys) { const v = k.split('.').reduce((o, p) => o?.[p], d); if (Array.isArray(v)) return v; }
    return [];
};

const tab = ref('health');
const tabs = [
    ['health', 'Health'], ['outbox', 'Outbox & Inbox'], ['cache', 'Cache admin'],
    ['audit', 'Audit search'], ['workflow', 'Workflow incidents'], ['operator', 'Operator config'],
];

const notice = ref(null);
const error = ref(null);
const flash = (msg) => { notice.value = msg; setTimeout(() => (notice.value = null), 4000); };
const fail = (e) => { error.value = e?.response?.data?.message ?? t('Request failed'); setTimeout(() => (error.value = null), 6000); };

// --- Health / runtime config (/api/health + /api/platform/config) ---
const health = ref(null);
const cfg = ref(null);
const healthLoading = ref(true);
const appEnv = computed(() => page.props.appEnv ?? '—');
const flags = computed(() => Object.entries(cfg.value?.featureFlags ?? {}));
const drivers = computed(() => Object.entries(cfg.value?.drivers ?? {}));
async function loadHealth() {
    healthLoading.value = true;
    try {
        const [h, c] = await Promise.allSettled([
            window.axios.get('/api/health'),
            window.axios.get('/api/platform/config'),
        ]);
        if (h.status === 'fulfilled') health.value = h.value.data;
        if (c.status === 'fulfilled') cfg.value = c.value.data;
    } catch (e) { /* leave tiles blank */ } finally { healthLoading.value = false; }
}

// --- Outbox / Inbox monitor (/api/noc/overview backlog + /api/noc/trace by key) ---
// There is no general "list recent outbox events" endpoint; outbox is reachable either as
// backlog counters (overview) or per correlation/business key (trace). We surface both, and
// note the general-feed gap explicitly rather than faking it.
const overview = ref(null);
const overviewAvailable = ref(true);
const traceKey = ref('');
const traceEvents = ref([]);
const traceTimeline = ref([]);
const traceLoading = ref(false);
const traceSearched = ref(false);
async function loadOverview() {
    try {
        const { data } = await window.axios.get('/api/noc/overview');
        overview.value = data;
        overviewAvailable.value = true;
    } catch (e) { overviewAvailable.value = false; }
}
async function loadTrace() {
    if (!traceKey.value.trim()) return;
    traceLoading.value = true; traceSearched.value = true; error.value = null;
    try {
        const { data } = await window.axios.get('/api/noc/trace', { params: { key: traceKey.value.trim() } });
        traceEvents.value = rows(data, 'events').map((e) => ({
            id: e.event_id ?? e.id ?? `${e.event_type}-${e.created_at}`,
            event_type: e.event_type, aggregate_type: e.aggregate_type, aggregate_id: e.aggregate_id,
            published_at: e.published_at, created_at: e.created_at, correlation_id: e.correlation_id,
        }));
        traceTimeline.value = rows(data, 'timeline');
    } catch (e) { traceEvents.value = []; traceTimeline.value = []; fail(e); }
    finally { traceLoading.value = false; }
}
const platformActivity = computed(() => traceTimeline.value.map((ev) => ({
    title: (ev.label ?? ev.source ?? '').toString().replace(/([A-Z])/g, ' $1').trim() || t('Event'),
    subtitle: [ev.source, ev.detail].filter(Boolean).join(' · '),
    at: dateFmt(ev.at),
    tone: ({ EVENT: 'indigo', WORKFLOW: 'blue', TASK: 'amber', PROVISIONING: 'cyan', NOTIFICATION: 'green', LOG: 'gray' })[ev.source] ?? 'indigo',
})));
const outboxCols = [
    { key: 'event_type', label: 'Event' },
    { key: 'aggregate', label: 'Aggregate' },
    { key: 'correlation_id', label: 'Correlation', class: 'font-mono text-xs' },
    { key: 'published_at', label: 'Published', align: 'right' },
];

// --- Cache admin (/api/admin/cache/stats requires module+aggregate; /api/admin/cache/invalidate) ---
const cacheQuery = ref({ module: 'plm', aggregate: 'wallet' });
const cacheStats = ref(null);
const cacheLoading = ref(false);
const cacheUnavailable = ref(false);
async function loadCacheStats() {
    if (!cacheQuery.value.module || !cacheQuery.value.aggregate) return;
    cacheLoading.value = true; cacheUnavailable.value = false; cacheStats.value = null;
    try {
        const { data } = await window.axios.get('/api/admin/cache/stats', { params: cacheQuery.value });
        cacheStats.value = data;
    } catch (e) {
        if (e?.response?.status === 403 || e?.response?.status === 404) cacheUnavailable.value = true;
        else fail(e);
    } finally { cacheLoading.value = false; }
}
const cacheMetrics = computed(() => Object.entries(cacheStats.value ?? {}).filter(([k]) => k !== 'keyPrefix'));
async function invalidateCache() {
    try {
        const { data } = await window.axios.post('/api/admin/cache/invalidate', {
            module: cacheQuery.value.module, aggregate: cacheQuery.value.aggregate, ids: ['*'],
        });
        flash(t('Invalidated entries: ') + (data.invalidated ?? 0));
        await loadCacheStats();
    } catch (e) { fail(e); }
}

// --- Audit search (/api/rbac/audit — the EM-CFG-03 §8.8 change-audit feed) ---
const audit = ref([]);
const auditFilters = ref({ targetType: '', targetId: '' });
const auditLoading = ref(false);
const auditUnavailable = ref(false);
const auditDetail = ref(null);
const auditOpen = ref(false);
const auditCols = [
    { key: 'created_at', label: 'When' },
    { key: 'change_type', label: 'Change' },
    { key: 'target_type', label: 'Target type' },
    { key: 'target_id', label: 'Target', class: 'font-mono text-xs' },
    { key: 'actor_uid', label: 'Actor' },
];
async function loadAudit() {
    auditLoading.value = true; auditUnavailable.value = false; error.value = null;
    try {
        const params = { size: 100 };
        if (auditFilters.value.targetType) params.targetType = auditFilters.value.targetType;
        if (auditFilters.value.targetId) params.targetId = auditFilters.value.targetId;
        const { data } = await window.axios.get('/api/rbac/audit', { params });
        audit.value = rows(data, 'items', 'data').map((a, i) => ({ _k: a.id ?? a.audit_id ?? i, ...a }));
    } catch (e) {
        if (e?.response?.status === 403 || e?.response?.status === 404) auditUnavailable.value = true;
        else fail(e);
    } finally { auditLoading.value = false; }
}
function openAudit(row) { auditDetail.value = row; auditOpen.value = true; }

// --- Workflow incident summary (/api/noc/overview + /api/itops/services queues) ---
const queues = ref(null);
async function loadQueues() {
    try {
        const { data } = await window.axios.get('/api/itops/services');
        queues.value = data.queues ?? null;
    } catch (e) { /* itops may be gated */ }
}

// --- Operator config (read-only; already in Inertia props) ---
const operatorConfig = computed(() => page.props.operatorConfig ?? null);
const operatorRows = computed(() => {
    const c = operatorConfig.value;
    if (!c) return [];
    const labels = {
        operator_code: 'Operator code', display_name: 'Display name', default_locale: 'Locale',
        currency_code: 'Currency', timezone: 'Timezone', date_format: 'Date format',
        theme_primary_color: 'Primary color', theme_logo_url: 'Logo URL', log_level: 'Log level',
        updated_by: 'Updated by', updated_at: 'Updated at',
    };
    return Object.entries(labels)
        .filter(([k]) => c[k] !== undefined && c[k] !== null)
        .map(([k, label]) => ({ label, value: c[k] }));
});

function loadTab() {
    const loaders = {
        health: loadHealth,
        outbox: loadOverview,
        cache: loadCacheStats,
        audit: loadAudit,
        workflow: async () => { await loadOverview(); await loadQueues(); },
        operator: async () => {},
    };
    (loaders[tab.value] ?? (() => {}))().catch(fail);
}
function selectTab(key) { tab.value = key; loadTab(); }
onMounted(loadTab);
</script>

<template>
    <Head :title="t('Admin')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="Admin" :crumbs="[{ label: 'Admin' }, { label: 'Console' }]">
                <template #actions>
                    <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium uppercase text-gray-600">{{ appEnv }}</span>
                    <button class="rounded border bg-white px-3 py-1 text-sm text-gray-700 hover:bg-gray-50" @click="loadTab">{{ t('Refresh') }}</button>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-5 py-2">
            <p v-if="notice" class="rounded bg-emerald-50 p-2 text-sm text-emerald-700">{{ notice }}</p>
            <p v-if="error" class="rounded bg-red-50 p-2 text-sm text-red-600">{{ error }}</p>

            <!-- Tab bar -->
            <div class="flex flex-wrap gap-2">
                <button v-for="[key, label] in tabs" :key="key" @click="selectTab(key)"
                    :class="tab === key ? 'bg-op text-white shadow-sm' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50'"
                    class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition">{{ t(label) }}</button>
            </div>

            <!-- HEALTH -->
            <div v-if="tab === 'health'" class="space-y-5">
                <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <StatCard :label="t('Status')" :value="health?.status ?? '—'" :sub="health?.service ?? ''"
                        :tone="health?.status === 'UP' ? 'emerald' : 'red'" :loading="healthLoading" />
                    <StatCard :label="t('Version')" :value="health?.version ?? '—'" :sub="t('build')" tone="indigo" :loading="healthLoading" />
                    <StatCard :label="t('Environment')" :value="appEnv" :sub="cfg?.operator ?? ''" tone="cyan" :loading="healthLoading" />
                    <StatCard :label="t('Locale / Currency')" :value="cfg?.locale ?? '—'" :sub="(cfg?.currency ?? '') + ' · ' + (cfg?.timezone ?? '')" tone="amber" :loading="healthLoading" />
                </div>

                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <Panel title="Feature flags" subtitle="Runtime config (/api/platform/config)">
                        <div v-if="flags.length" class="space-y-2">
                            <div v-for="[name, on] in flags" :key="name" class="flex items-center justify-between border-b border-gray-50 py-1.5 text-sm last:border-0">
                                <span class="font-medium text-gray-700">{{ name }}</span>
                                <StatusBadge :status="on ? 'ACTIVE' : 'DISABLED'" />
                            </div>
                        </div>
                        <p v-else class="text-sm text-gray-400">{{ t('No flags reported.') }}</p>
                    </Panel>

                    <Panel title="Platform drivers" subtitle="Event bus / workflow / rules engines">
                        <div v-if="drivers.length" class="space-y-2">
                            <div v-for="[name, value] in drivers" :key="name" class="flex items-center justify-between border-b border-gray-50 py-1.5 text-sm last:border-0">
                                <span class="font-medium text-gray-700">{{ name }}</span>
                                <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-600">{{ value ?? '—' }}</span>
                            </div>
                        </div>
                        <p v-else class="text-sm text-gray-400">{{ t('No drivers reported.') }}</p>
                    </Panel>
                </div>

                <Panel title="Service details" subtitle="From /api/health">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                        <div><dt class="text-xs uppercase text-gray-400">{{ t('Service') }}</dt><dd class="text-gray-800">{{ health?.service ?? '—' }}</dd></div>
                        <div><dt class="text-xs uppercase text-gray-400">{{ t('Country') }}</dt><dd class="text-gray-800">{{ cfg?.country ?? '—' }}</dd></div>
                        <div><dt class="text-xs uppercase text-gray-400">{{ t('Server time') }}</dt><dd class="text-gray-800">{{ dateFmt(health?.time) }}</dd></div>
                        <div><dt class="text-xs uppercase text-gray-400">{{ t('Correlation') }}</dt><dd class="truncate font-mono text-xs text-gray-600">{{ health?.correlationId ?? '—' }}</dd></div>
                    </dl>
                </Panel>
            </div>

            <!-- OUTBOX & INBOX -->
            <div v-if="tab === 'outbox'" class="space-y-5">
                <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <StatCard :label="t('Outbox backlog')" :value="overview?.outboxBacklog ?? '—'" :sub="t('unpublished events')"
                        :tone="(overview?.outboxBacklog ?? 0) > 0 ? 'amber' : 'emerald'" :loading="!overview && overviewAvailable" />
                    <StatCard :label="t('Workflow incidents')" :value="overview?.workflowIncidents ?? '—'" :sub="t('failed / stuck tasks')"
                        :tone="(overview?.workflowIncidents ?? 0) > 0 ? 'red' : 'emerald'" :loading="!overview && overviewAvailable" />
                    <StatCard :label="t('Running instances')" :value="overview?.runningInstances ?? '—'" tone="blue" :loading="!overview && overviewAvailable" />
                    <StatCard :label="t('Provisioning mismatches')" :value="overview?.provisioningMismatches ?? '—'" :sub="t('open reconciliation')"
                        :tone="(overview?.provisioningMismatches ?? 0) > 0 ? 'amber' : 'emerald'" :loading="!overview && overviewAvailable" />
                </div>

                <p v-if="!overviewAvailable" class="rounded bg-amber-50 p-3 text-sm text-amber-700">
                    {{ t('Backlog overview not available (requires itops.view).') }}
                </p>

                <Panel title="Outbox monitor" subtitle="Inspect outbox events by correlation or business key (/api/noc/trace)">
                    <template #actions>
                        <div class="flex items-center gap-2">
                            <input v-model="traceKey" :placeholder="t('correlation / business key')" class="w-64 rounded border-gray-300 text-sm" @keyup.enter="loadTrace" />
                            <button class="rounded bg-op px-3 py-1 text-sm text-white" @click="loadTrace">{{ t('Trace') }}</button>
                        </div>
                    </template>
                    <p class="mb-3 rounded bg-gray-50 p-2 text-xs text-gray-500">
                        {{ t('Note: there is no general "recent outbox events" feed endpoint; events are exposed per key via the NOC trace. Enter a key to list its outbox events.') }}
                    </p>
                    <DataTable :columns="outboxCols" :rows="traceEvents" row-key="id" :loading="traceLoading"
                        :empty="traceSearched ? 'No outbox events for this key.' : 'Enter a key above to inspect outbox events.'">
                        <template #cell-aggregate="{ row }">
                            <span class="text-gray-700">{{ row.aggregate_type }}</span>
                            <span class="ml-1 font-mono text-xs text-gray-400">{{ row.aggregate_id }}</span>
                        </template>
                        <template #cell-published_at="{ value }">
                            <StatusBadge v-if="!value" status="PENDING" />
                            <span v-else class="text-xs text-gray-500">{{ dateFmt(value) }}</span>
                        </template>
                    </DataTable>
                </Panel>

                <Panel title="Recent platform activity" subtitle="Merged event/workflow/provisioning/log narrative for the traced key">
                    <Timeline :events="platformActivity" />
                </Panel>
            </div>

            <!-- CACHE ADMIN -->
            <div v-if="tab === 'cache'" class="space-y-5">
                <Panel title="Cache stats" subtitle="FOUNDATION_CACHE §11 — hit/miss counters per module/aggregate prefix">
                    <template #actions>
                        <div class="flex items-center gap-2">
                            <input v-model="cacheQuery.module" :placeholder="t('module')" class="w-28 rounded border-gray-300 text-sm" />
                            <input v-model="cacheQuery.aggregate" :placeholder="t('aggregate')" class="w-32 rounded border-gray-300 text-sm" />
                            <button class="rounded border bg-white px-3 py-1 text-sm" @click="loadCacheStats">{{ t('Load') }}</button>
                            <button class="rounded bg-red-50 px-3 py-1 text-sm text-red-600 hover:bg-red-100" @click="invalidateCache">{{ t('Invalidate') }}</button>
                        </div>
                    </template>

                    <p v-if="cacheUnavailable" class="rounded bg-amber-50 p-3 text-sm text-amber-700">
                        {{ t('Cache admin not available (requires itops.manage).') }}
                    </p>
                    <div v-else-if="cacheLoading" class="text-sm text-gray-400">{{ t('Loading…') }}</div>
                    <div v-else-if="cacheStats" class="space-y-3">
                        <div class="rounded bg-gray-50 p-2 font-mono text-xs text-gray-600">{{ t('Key prefix') }}: {{ cacheStats.keyPrefix }}</div>
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                            <StatCard v-for="[name, value] in cacheMetrics" :key="name" :label="name" :value="value ?? 0" tone="cyan" />
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400">{{ t('Enter a module + aggregate and load stats.') }}</p>
                </Panel>
            </div>

            <!-- AUDIT SEARCH -->
            <div v-if="tab === 'audit'" class="space-y-5">
                <Panel title="Audit search" subtitle="RBAC change-audit feed (EM-CFG-03 §8.8 — /api/rbac/audit)">
                    <template #actions>
                        <div class="flex items-center gap-2">
                            <input v-model="auditFilters.targetType" :placeholder="t('target type')" class="w-32 rounded border-gray-300 text-sm" />
                            <input v-model="auditFilters.targetId" :placeholder="t('target id')" class="w-40 rounded border-gray-300 text-sm" @keyup.enter="loadAudit" />
                            <button class="rounded bg-op px-3 py-1 text-sm text-white" @click="loadAudit">{{ t('Search') }}</button>
                        </div>
                    </template>

                    <p v-if="auditUnavailable" class="rounded bg-amber-50 p-3 text-sm text-amber-700">
                        {{ t('Audit feed not available (requires rbac access).') }}
                    </p>
                    <DataTable v-else :columns="auditCols" :rows="audit" row-key="_k" :loading="auditLoading"
                        empty="No audit entries match." @select="openAudit">
                        <template #cell-created_at="{ value }"><span class="text-xs text-gray-500">{{ dateFmt(value) }}</span></template>
                        <template #cell-change_type="{ row }">
                            <span class="font-medium text-gray-800">{{ (row.change_type ?? row.action ?? '—').toString().replaceAll('_', ' ') }}</span>
                        </template>
                        <template #cell-actor_uid="{ row }">
                            <span class="text-gray-600">{{ row.actor_uid ?? row.changed_by ?? '—' }}</span>
                        </template>
                    </DataTable>
                </Panel>
            </div>

            <!-- WORKFLOW INCIDENTS -->
            <div v-if="tab === 'workflow'" class="space-y-5">
                <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <StatCard :label="t('Workflow incidents')" :value="overview?.workflowIncidents ?? '—'" :sub="t('failed / stuck')"
                        :tone="(overview?.workflowIncidents ?? 0) > 0 ? 'red' : 'emerald'" :loading="!overview && overviewAvailable" />
                    <StatCard :label="t('Tasks queued')" :value="queues?.workflowTasksCreated ?? '—'" :sub="t('created')" tone="indigo" />
                    <StatCard :label="t('Running instances')" :value="overview?.runningInstances ?? '—'" tone="blue" :loading="!overview && overviewAvailable" />
                    <StatCard :label="t('SLA-overdue tickets')" :value="overview?.slaOverdueTickets ?? '—'"
                        :tone="(overview?.slaOverdueTickets ?? 0) > 0 ? 'amber' : 'emerald'" :loading="!overview && overviewAvailable" />
                </div>

                <p v-if="!overviewAvailable" class="rounded bg-amber-50 p-3 text-sm text-amber-700">
                    {{ t('Incident summary not available (requires itops.view).') }}
                </p>

                <Panel title="Heartbeat services" subtitle="From the NOC overview pane (/api/noc/overview)">
                    <div v-if="rows(overview, 'services').length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        <div v-for="s in rows(overview, 'services')" :key="s.service" class="rounded-lg bg-gray-50 p-3 ring-1 ring-gray-100">
                            <div class="flex items-center justify-between">
                                <strong class="text-sm text-gray-800">{{ s.service }}</strong>
                                <StatusBadge :status="s.status === 'UP' ? 'ACTIVE' : 'FAILED'" />
                            </div>
                            <div class="mt-1 text-xs text-gray-400">{{ t('seen') }} {{ dateFmt(s.last_seen_at) }}</div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400">{{ t('No service heartbeats reported.') }}</p>
                </Panel>
            </div>

            <!-- OPERATOR CONFIG -->
            <div v-if="tab === 'operator'" class="space-y-5">
                <Panel title="Operator configuration" subtitle="Current deployment config (read-only — edited via IT-Ops settings)">
                    <div v-if="operatorRows.length" class="grid grid-cols-1 divide-y divide-gray-50 sm:grid-cols-2 sm:gap-x-8 sm:divide-y-0">
                        <div v-for="r in operatorRows" :key="r.label" class="flex items-center justify-between py-2 text-sm sm:border-b sm:border-gray-50">
                            <span class="text-gray-500">{{ t(r.label) }}</span>
                            <span class="font-medium text-gray-800">
                                <span v-if="r.label === 'Primary color'" class="inline-flex items-center gap-1.5">
                                    <span class="inline-block h-3 w-3 rounded-full ring-1 ring-gray-200" :style="{ backgroundColor: r.value }"></span>{{ r.value }}
                                </span>
                                <span v-else>{{ r.value }}</span>
                            </span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400">{{ t('No operator config present for this deployment.') }}</p>
                </Panel>
            </div>
        </div>

        <!-- Audit detail drawer -->
        <Drawer v-model:open="auditOpen" :title="t('Audit entry')" width="max-w-xl">
            <div v-if="auditDetail" class="space-y-4">
                <Timeline :events="[{
                    title: (auditDetail.change_type ?? auditDetail.action ?? 'Change').toString().replaceAll('_', ' '),
                    subtitle: (auditDetail.target_type ?? '') + ' · ' + (auditDetail.target_id ?? ''),
                    at: dateFmt(auditDetail.created_at), tone: 'indigo',
                }]" />
                <div class="rounded-lg bg-gray-900 p-3">
                    <pre class="overflow-x-auto whitespace-pre-wrap text-xs text-gray-100">{{ JSON.stringify(auditDetail, null, 2) }}</pre>
                </div>
            </div>
        </Drawer>
    </AuthenticatedLayout>
</template>
