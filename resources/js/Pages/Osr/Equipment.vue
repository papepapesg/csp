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

// FE-APP-01 §12 — Equipment backoffice surface over OSR-INSTANCE-01 (serialized instance
// registry + lifecycle ledger) and OSR-RMA-01 (equipment swap / RMA workflow + recovery
// exceptions). Stock/warehouse balances live separately at Osr/Warehouse.vue; this page is
// the instance + swap/RMA + recovery view. Resilient: every fetch is guarded and degrades to
// a graceful empty state, so a down backend blanks only its own panel.
const { t, dateFmt } = useI18n();

// One list-shape normalizer for every read: SOPHIX paginated envelopes are { items, … };
// some helpers hand back { data:[…] } or a bare array. Read once, normalize everywhere.
const rows = (d) => (Array.isArray(d) ? d : (d?.items ?? d?.data ?? []));

// --- swap lifecycle vocabulary (OSR-RMA-01) ------------------------------------------------
// Ordered happy-path spine for the StageTracker. Terminal exception states sit off-spine and
// recolour the final node.
const SWAP_STAGES = [
    ['CREATED', 'Created'],
    ['AWAITING_SLOT', 'Awaiting slot'],
    ['WO_CREATED', 'Work order'],
    ['FIELD_VISIT_IN_PROGRESS', 'Field visit'],
    ['SOURCE_RECOVERED', 'Recovered'],
    ['COMPLETED', 'Completed'],
];
const EXCEPTION_STATUSES = ['FAILED', 'COMPLETED_WITHOUT_RECOVERY'];
const swapTone = (s) => (s === 'COMPLETED' ? 'green' : EXCEPTION_STATUSES.includes(s) ? 'red' : 'blue');

// --- tabs ---------------------------------------------------------------------------------
const tab = ref('instances');
const tabs = [
    ['instances', 'Equipment instances'],
    ['swaps', 'Swaps & RMA'],
];

// --- SKU lookup (best-effort, for friendlier type labels) ---------------------------------
const skuById = ref({});
async function loadSkus() {
    try {
        const { data } = await window.axios.get('/api/equipment-skus');
        const map = {};
        for (const s of rows(data)) map[s.sku_id ?? s.id] = s.name ?? s.sku_id;
        skuById.value = map;
    } catch (e) { /* type column falls back to the raw sku_id */ }
}
const skuLabel = (id) => skuById.value[id] ?? id ?? '—';

// --- equipment instances (OSR-INSTANCE-01) ------------------------------------------------
const instances = ref([]);
const instancesLoading = ref(false);
const serial = ref('');
const stateFilter = ref('');
const INSTANCE_STATES = [
    'IN_MAIN_WAREHOUSE', 'IN_CONTRACTOR_STOCK', 'IN_FIELD_ACTIVE', 'IN_FIELD_DEFECTIVE',
    'RECOVERED_BY_CONTRACTOR', 'RESERVED_FOR_WO', 'RETURNED', 'FAULTY', 'RETIRED',
];
async function loadInstances() {
    instancesLoading.value = true;
    try {
        const params = {};
        if (serial.value.trim()) params.serial = serial.value.trim();
        if (stateFilter.value) params.state = stateFilter.value;
        const { data } = await window.axios.get('/api/equipment-instances', { params });
        instances.value = rows(data);
    } catch (e) { instances.value = []; } finally { instancesLoading.value = false; }
}

const instanceColumns = [
    { key: 'serial', label: 'Serial', class: 'font-mono text-xs' },
    { key: 'sku_id', label: 'SKU / type' },
    { key: 'state', label: 'Status' },
    { key: 'assigned', label: 'Assigned to' },
    { key: 'updated_at', label: 'Updated', align: 'right' },
];

// --- instance detail drawer (with lifecycle Timeline) -------------------------------------
const instanceOpen = ref(false);
const instanceDetail = ref(null);
const instanceDetailLoading = ref(false);
async function openInstance(row) {
    instanceOpen.value = true;
    instanceDetail.value = row;
    instanceDetailLoading.value = true;
    try {
        // /show eager-loads lifecycleEvents — the only path that exposes the event ledger.
        const { data } = await window.axios.get(`/api/equipment-instances/${row.instance_id}`);
        instanceDetail.value = data?.data ?? data ?? row;
    } catch (e) { /* keep the list row; lifecycle simply shows empty */ } finally { instanceDetailLoading.value = false; }
}
// Append-only ledger → Timeline events, newest first.
const lifecycleEvents = computed(() => {
    const evs = instanceDetail.value?.lifecycle_events ?? instanceDetail.value?.lifecycleEvents ?? [];
    return [...evs]
        .sort((a, b) => (b.event_sequence ?? 0) - (a.event_sequence ?? 0))
        .map((e) => ({
            title: (e.event_type ?? 'Event').replaceAll('_', ' '),
            subtitle: [
                e.from_state && e.to_state ? `${e.from_state} → ${e.to_state}` : (e.to_state ?? e.from_state),
                e.reason_code, e.contractor_id, e.location_id, e.reference,
            ].filter(Boolean).join(' · ') || undefined,
            at: dateFmt(e.created_at),
            tone: e.to_state === 'FAULTY' || e.to_state === 'IN_FIELD_DEFECTIVE' ? 'red'
                : e.to_state === 'RETIRED' ? 'gray' : 'indigo',
        }));
});

// --- swap requests (OSR-RMA-01) -----------------------------------------------------------
const swaps = ref([]);
const swapsLoading = ref(false);
async function loadSwaps() {
    swapsLoading.value = true;
    try {
        const { data } = await window.axios.get('/api/swap-requests');
        swaps.value = rows(data);
    } catch (e) { swaps.value = []; } finally { swapsLoading.value = false; }
}
const swapColumns = [
    { key: 'swap_id', label: 'Swap', class: 'font-mono text-xs' },
    { key: 'kind', label: 'Kind' },
    { key: 'status', label: 'Status' },
    { key: 'subscription_id', label: 'Subscription', class: 'font-mono text-xs' },
    { key: 'recovery_contractor_id', label: 'Recovery contractor' },
    { key: 'created_at', label: 'Created', align: 'right' },
];
const recoveryExceptions = computed(() => swaps.value.filter((s) => EXCEPTION_STATUSES.includes(s.status)));

// --- swap detail drawer (StageTracker) ----------------------------------------------------
const swapOpen = ref(false);
const swapDetail = ref(null);
const swapDetailLoading = ref(false);
async function openSwap(row) {
    swapOpen.value = true;
    swapDetail.value = row;
    swapDetailLoading.value = true;
    try {
        const { data } = await window.axios.get(`/api/swap-requests/${row.swap_id}`);
        swapDetail.value = data?.data ?? data ?? row;
    } catch (e) { /* keep the list row */ } finally { swapDetailLoading.value = false; }
}
// Map current status onto the ordered spine: stages before it are done, the matching one is
// current (or failed/done if terminal), later ones pending. Exception terminals mark the
// reached point red.
const swapTracker = computed(() => {
    const status = swapDetail.value?.status;
    const order = SWAP_STAGES.map(([k]) => k);
    const isException = EXCEPTION_STATUSES.includes(status);
    // SOURCE_RECOVERED reached for COMPLETED; for *_WITHOUT_RECOVERY treat recovery as not done.
    let idx = order.indexOf(status);
    if (status === 'COMPLETED_WITHOUT_RECOVERY') idx = order.indexOf('FIELD_VISIT_IN_PROGRESS');
    if (status === 'FAILED' && idx < 0) idx = order.indexOf('AWAITING_SLOT');
    if (idx < 0) idx = order.length - 1;
    return SWAP_STAGES.map(([key, label], i) => ({
        key, label,
        state: i < idx ? 'done'
            : i === idx ? (isException ? 'failed' : status === 'COMPLETED' ? 'done' : 'current')
            : 'pending',
    }));
});

// --- summary stat cards -------------------------------------------------------------------
const awaitingSlot = computed(() => swaps.value.filter((s) => s.status === 'AWAITING_SLOT').length);
const openRmas = computed(() => swaps.value.filter((s) => !['COMPLETED', ...EXCEPTION_STATUSES].includes(s.status)).length);
const exceptionsCount = computed(() => recoveryExceptions.value.length);
const fieldDefective = computed(() => instances.value.filter((i) => ['IN_FIELD_DEFECTIVE', 'FAULTY'].includes(i.state)).length);

function selectTab(key) {
    tab.value = key;
    if (key === 'instances' && !instances.value.length) loadInstances();
    if (key === 'swaps' && !swaps.value.length) loadSwaps();
}

onMounted(() => {
    loadSkus();
    loadInstances();
    loadSwaps(); // load both up front so summary cards are populated regardless of active tab
});
</script>

<template>
    <Head :title="t('Equipment')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="Equipment" :crumbs="[{ label: 'Commerce' }, { label: 'Equipment' }]" />
        </template>

        <div class="mx-auto max-w-7xl space-y-5">
            <!-- Summary -->
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard :label="t('Swaps awaiting slot')" :value="awaitingSlot" tone="amber"
                    :sub="t('OSR-RMA-01 scheduling queue')" :loading="swapsLoading" @select="selectTab('swaps')" />
                <StatCard :label="t('Open RMAs')" :value="openRmas" tone="indigo"
                    :sub="t('In-flight swap requests')" :loading="swapsLoading" @select="selectTab('swaps')" />
                <StatCard :label="t('Recovery exceptions')" :value="exceptionsCount" tone="red"
                    :sub="t('Failed / no recovery')" :loading="swapsLoading" @select="selectTab('swaps')" />
                <StatCard :label="t('Defective in field')" :value="fieldDefective" tone="orange"
                    :sub="t('Instances FAULTY / defective')" :loading="instancesLoading" @select="selectTab('instances')" />
            </div>

            <!-- Recovery exceptions (always visible — operational hotspot) -->
            <Panel v-if="recoveryExceptions.length" title="Recovery exceptions"
                subtitle="Swaps that ended FAILED or COMPLETED_WITHOUT_RECOVERY (OSR-RMA-01)">
                <template #actions>
                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20">
                        {{ recoveryExceptions.length }}
                    </span>
                </template>
                <DataTable :columns="swapColumns" :rows="recoveryExceptions" row-key="swap_id"
                    :loading="swapsLoading" empty="No recovery exceptions." @select="openSwap">
                    <template #cell-kind="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-created_at="{ value }">{{ dateFmt(value) }}</template>
                </DataTable>
            </Panel>

            <!-- Tabs -->
            <div class="flex flex-wrap gap-2">
                <button v-for="[key, label] in tabs" :key="key" @click="selectTab(key)"
                    :class="tab === key ? 'bg-op text-white ring-op' : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50'"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset transition">{{ t(label) }}</button>
            </div>

            <!-- EQUIPMENT INSTANCES -->
            <Panel v-show="tab === 'instances'" title="Equipment instances"
                subtitle="Serialized device registry (OSR-INSTANCE-01)">
                <template #actions>
                    <input v-model="serial" @keyup.enter="loadInstances" :placeholder="t('Search serial…')"
                        class="rounded-md border-gray-200 text-sm focus:border-op focus:ring-op" />
                    <select v-model="stateFilter" @change="loadInstances"
                        class="rounded-md border-gray-200 text-sm focus:border-op focus:ring-op">
                        <option value="">{{ t('All states') }}</option>
                        <option v-for="s in INSTANCE_STATES" :key="s" :value="s">{{ t(s.replaceAll('_', ' ')) }}</option>
                    </select>
                    <button @click="loadInstances"
                        class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:bg-op-dark">{{ t('Search') }}</button>
                </template>

                <DataTable :columns="instanceColumns" :rows="instances" row-key="instance_id"
                    :loading="instancesLoading" empty="No equipment instances found." @select="openInstance">
                    <template #cell-sku_id="{ value }">
                        <span class="text-gray-700">{{ skuLabel(value) }}</span>
                    </template>
                    <template #cell-state="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-assigned="{ row }">
                        <span v-if="row.customer_id || row.subscription_id" class="text-xs text-gray-600">
                            {{ row.customer_id ?? row.subscription_id }}
                        </span>
                        <span v-else class="text-xs text-gray-400">—</span>
                    </template>
                    <template #cell-updated_at="{ value }">{{ dateFmt(value) }}</template>
                </DataTable>
            </Panel>

            <!-- SWAPS & RMA -->
            <Panel v-show="tab === 'swaps'" title="Swap & RMA queue"
                subtitle="Equipment swap requests and their lifecycle (OSR-RMA-01)">
                <template #actions>
                    <button @click="loadSwaps"
                        class="rounded-md border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50">{{ t('Refresh') }}</button>
                </template>

                <DataTable :columns="swapColumns" :rows="swaps" row-key="swap_id"
                    :loading="swapsLoading" empty="No swap requests." @select="openSwap">
                    <template #cell-kind="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-subscription_id="{ value }">{{ value ?? '—' }}</template>
                    <template #cell-created_at="{ value }">{{ dateFmt(value) }}</template>
                </DataTable>
            </Panel>
        </div>

        <!-- Instance detail drawer -->
        <Drawer v-model:open="instanceOpen" :title="t('Equipment instance')">
            <div v-if="instanceDetail" class="space-y-5">
                <div>
                    <div class="font-mono text-lg font-semibold text-gray-900">{{ instanceDetail.serial }}</div>
                    <div class="mt-1 flex items-center gap-2">
                        <StatusBadge :status="instanceDetail.state" />
                        <span class="text-xs text-gray-500">{{ skuLabel(instanceDetail.sku_id) }}</span>
                    </div>
                </div>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Instance ID') }}</dt>
                        <dd class="font-mono text-xs text-gray-700">{{ instanceDetail.instance_id }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('MAC address') }}</dt>
                        <dd class="font-mono text-xs text-gray-700">{{ instanceDetail.mac_address ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Location') }}</dt>
                        <dd class="text-gray-700">{{ instanceDetail.location_id ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Customer') }}</dt>
                        <dd class="text-gray-700">{{ instanceDetail.customer_id ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Subscription') }}</dt>
                        <dd class="font-mono text-xs text-gray-700">{{ instanceDetail.subscription_id ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Updated') }}</dt>
                        <dd class="text-gray-700">{{ dateFmt(instanceDetail.updated_at) }}</dd></div>
                </dl>

                <div>
                    <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Lifecycle') }}</h4>
                    <p v-if="instanceDetailLoading" class="text-sm text-gray-400">{{ t('Loading…') }}</p>
                    <Timeline v-else :events="lifecycleEvents" />
                </div>
            </div>
        </Drawer>

        <!-- Swap detail drawer -->
        <Drawer v-model:open="swapOpen" :title="t('Swap request')">
            <div v-if="swapDetail" class="space-y-5">
                <div class="flex items-center justify-between">
                    <div class="font-mono text-sm font-semibold text-gray-900">{{ swapDetail.swap_id }}</div>
                    <StatusBadge :status="swapDetail.status" />
                </div>

                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-100">
                    <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Swap lifecycle') }}</h4>
                    <StageTracker :stages="swapTracker" />
                </div>

                <div v-if="EXCEPTION_STATUSES.includes(swapDetail.status)"
                    class="rounded-lg bg-red-50 p-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">
                    <span class="font-medium">{{ t('Recovery exception') }}:</span>
                    {{ t(String(swapDetail.status).replaceAll('_', ' ')) }}
                    <span v-if="swapDetail.failure_code"> — {{ swapDetail.failure_code }}</span>
                </div>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Kind') }}</dt>
                        <dd><StatusBadge :status="swapDetail.kind" /></dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Created') }}</dt>
                        <dd class="text-gray-700">{{ dateFmt(swapDetail.created_at) }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Source instance') }}</dt>
                        <dd class="font-mono text-xs text-gray-700">{{ swapDetail.source_instance_id ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Target instance') }}</dt>
                        <dd class="font-mono text-xs text-gray-700">{{ swapDetail.target_instance_id ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Subscription') }}</dt>
                        <dd class="font-mono text-xs text-gray-700">{{ swapDetail.subscription_id ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Customer') }}</dt>
                        <dd class="text-gray-700">{{ swapDetail.customer_id ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Recovery contractor') }}</dt>
                        <dd class="text-gray-700">{{ swapDetail.recovery_contractor_id ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">{{ t('Work order') }}</dt>
                        <dd class="font-mono text-xs text-gray-700">{{ swapDetail.work_order_id ?? '—' }}</dd></div>
                </dl>
            </div>
        </Drawer>
    </AuthenticatedLayout>
</template>
