<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import Timeline from '@/Components/Bss/Timeline.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

const { t, dateFmt } = useI18n();

// Warehouse backoffice (OSR-01/02/05 + OSR-INSTANCE/RMA). The services were already
// there — stock chain with reservations, procurement with approval, counts,
// serialized instances, swaps; this is their operations UI. Restyled onto the shared
// Bss visual kit (FE-APP-01 §12); the data layer / API endpoints are unchanged.
const tab = ref('stock'); // stock | pos | equipment | swaps
const tabs = [
    ['stock', 'Stock chain'],
    ['pos', 'Purchase orders'],
    ['equipment', 'Equipment'],
    ['swaps', 'Swaps / RMA'],
];

const error = ref(null);
const notice = ref(null);
const flash = (e) => { error.value = e.response?.data?.message ?? t('Request failed'); setTimeout(() => (error.value = null), 6000); };
const flashNotice = (msg) => { notice.value = msg; setTimeout(() => (notice.value = null), 4000); };

const locations = ref([]);
const balances = ref([]);
const balanceFilter = ref('');
const move = ref({ sku_id: '', location_id: '', quantity: 1, reason_code: 'RECEIPT', reference: '' });
const reasonCodes = ['RECEIPT', 'ISSUE', 'TRANSFER_IN', 'TRANSFER_OUT', 'INSTALL', 'RETURN', 'ADJUST'];

const pos = ref([]);
const equipment = ref([]);
const swaps = ref([]);

const loadingStock = ref(true);
const loadingPos = ref(true);
const loadingEquipment = ref(true);
const loadingSwaps = ref(true);

async function loadStock() {
    loadingStock.value = true;
    try {
        const l = await window.axios.get('/api/stock-locations', { params: { size: 100 } });
        locations.value = l.data.items ?? [];
        const b = await window.axios.get('/api/stock-balances', { params: { location: balanceFilter.value || undefined } });
        balances.value = b.data.items ?? [];
    } catch (e) { flash(e); } finally { loadingStock.value = false; }
}
async function postMove() {
    try {
        await window.axios.post('/api/stock-movements', { ...move.value, quantity: Number(move.value.quantity) }, { headers: { 'Idempotency-Key': `mv-${Date.now()}` } });
        flashNotice(t('Movement recorded')); await loadStock();
    } catch (e) { flash(e); }
}
async function loadPos() {
    loadingPos.value = true;
    try {
        const { data } = await window.axios.get('/api/purchase-orders', { params: { size: 50 } });
        pos.value = data.items ?? data.content ?? [];
    } catch (e) { flash(e); } finally { loadingPos.value = false; }
}
async function poAction(po, action) {
    try { await window.axios.post(`/api/purchase-orders/${po.po_id ?? po.purchase_order_id ?? po.id}/${action}`); await loadPos(); flashNotice(`PO ${action}d`); } catch (e) { flash(e); }
}
async function loadEquipment() {
    loadingEquipment.value = true;
    try {
        const { data } = await window.axios.get('/api/equipment-instances', { params: { size: 50 } });
        equipment.value = data.items ?? data.content ?? [];
    } catch (e) { flash(e); } finally { loadingEquipment.value = false; }
}
async function transition(inst, toState) {
    try { await window.axios.post(`/api/equipment-instances/${inst.instance_id}/transition`, { state: toState }); await loadEquipment(); } catch (e) { flash(e); }
}
async function loadSwaps() {
    loadingSwaps.value = true;
    try {
        const { data } = await window.axios.get('/api/swap-requests', { params: { size: 50 } });
        swaps.value = data.items ?? data.content ?? [];
    } catch (e) { flash(e); } finally { loadingSwaps.value = false; }
}

const nextStates = {
    IN_MAIN_WAREHOUSE: ['IN_CONTRACTOR_STOCK', 'FAULTY', 'RETIRED'],
    IN_CONTRACTOR_STOCK: ['IN_FIELD_ACTIVE', 'IN_MAIN_WAREHOUSE', 'FAULTY'],
    IN_FIELD_ACTIVE: ['IN_FIELD_DEFECTIVE', 'RETURNED', 'FAULTY'],
    IN_FIELD_DEFECTIVE: ['RECOVERED_BY_CONTRACTOR', 'FAULTY'],
    RECOVERED_BY_CONTRACTOR: ['IN_CONTRACTOR_STOCK', 'FAULTY', 'RETIRED'],
    RETURNED: ['IN_MAIN_WAREHOUSE', 'FAULTY', 'RETIRED'],
    FAULTY: ['RETIRED', 'IN_MAIN_WAREHOUSE'],
};

// --- Derived summary (from already-loaded data, no extra requests) ---
const distinctSkus = computed(() => new Set(balances.value.map((b) => b.sku_id)).size);
const lowStock = computed(() => balances.value.filter((b) => (b.quantity - (b.qty_reserved ?? 0)) <= 0).length);
const openPos = computed(() => pos.value.filter((p) => ['DRAFT', 'PENDING_APPROVAL', 'PENDING', 'APPROVED'].includes(p.status)).length);
const pendingSwaps = computed(() => swaps.value.filter((s) => !['RESOLVED', 'COMPLETED', 'CANCELLED', 'CLOSED', 'REJECTED'].includes(s.status)).length);

// --- DataTable column definitions ---
const balanceColumns = [
    { key: 'location_id', label: 'Location', class: 'text-xs' },
    { key: 'sku_id', label: 'SKU', class: 'font-mono text-xs' },
    { key: 'quantity', label: 'On hand', align: 'right' },
    { key: 'qty_reserved', label: 'Reserved', align: 'right' },
    { key: 'available', label: 'Available', align: 'right', class: 'font-semibold' },
];
const poColumns = [
    { key: 'id', label: 'Purchase order', class: 'font-mono text-xs' },
    { key: 'status', label: 'Status' },
    { key: 'vendor', label: 'Vendor', class: 'text-xs text-gray-500' },
];
const equipmentColumns = [
    { key: 'serial', label: 'Serial', class: 'font-mono text-xs' },
    { key: 'sku_id', label: 'SKU', class: 'font-mono text-xs' },
    { key: 'state', label: 'State' },
    { key: 'subscription_id', label: 'Subscription', class: 'text-xs' },
    { key: 'transition', label: 'Transition' },
];
const swapColumns = [
    { key: 'swap_id', label: 'Swap', class: 'font-mono text-xs' },
    { key: 'kind', label: 'Kind' },
    { key: 'status', label: 'Status' },
    { key: 'context', label: 'Subscription · source', class: 'text-xs text-gray-500' },
];

// Equipment lifecycle states as a Timeline (the chain the instance walks through).
const lifecycleEvents = [
    { title: 'In main warehouse', subtitle: t('Received & stocked'), tone: 'gray' },
    { title: 'In contractor stock', subtitle: t('Allocated to a contractor'), tone: 'indigo' },
    { title: 'In field active', subtitle: t('Installed & serving a subscription'), tone: 'green' },
    { title: 'In field defective / recovered', subtitle: t('Faulted, awaiting recovery'), tone: 'amber' },
    { title: 'Returned / retired', subtitle: t('Back to warehouse or end-of-life'), tone: 'gray' },
];

function selectTab(key) { tab.value = key; }

onMounted(() => { loadStock(); loadPos(); loadEquipment(); loadSwaps(); });
</script>

<template>
    <Head :title="t('Warehouse')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="Warehouse" :crumbs="[{ label: 'Operations' }, { label: 'Warehouse' }]">
                <template #actions>
                    <button class="rounded border border-gray-200 bg-white px-3 py-1 text-xs text-gray-600 hover:bg-gray-50"
                        @click="loadStock(); loadPos(); loadEquipment(); loadSwaps()">{{ t('Refresh') }}</button>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-5">
            <p v-if="notice" class="rounded bg-emerald-100 p-2 text-sm text-emerald-700">{{ notice }}</p>
            <p v-if="error" class="rounded bg-red-100 p-2 text-sm text-red-700">{{ error }}</p>

            <!-- Summary StatCards (derived from already-loaded data) -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="SKUs in stock" :value="distinctSkus" sub="Distinct SKUs across locations" tone="indigo" :loading="loadingStock" @select="selectTab('stock')" />
                <StatCard label="Out of stock" :value="lowStock" sub="No available quantity" tone="red" :loading="loadingStock" @select="selectTab('stock')" />
                <StatCard label="Open purchase orders" :value="openPos" sub="Awaiting approval or receipt" tone="amber" :loading="loadingPos" @select="selectTab('pos')" />
                <StatCard label="Pending swaps / RMA" :value="pendingSwaps" sub="Unresolved swap requests" tone="cyan" :loading="loadingSwaps" @select="selectTab('swaps')" />
            </div>

            <div class="flex flex-wrap gap-2">
                <button v-for="[key, label] in tabs" :key="key" @click="selectTab(key)"
                    :class="tab === key ? 'bg-op text-white' : 'bg-white text-gray-600 ring-1 ring-gray-200'"
                    class="rounded px-3 py-1 text-sm transition">{{ t(label) }}</button>
            </div>

            <!-- STOCK CHAIN -->
            <div v-if="tab === 'stock'" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <Panel title="Stock balances" subtitle="On-hand / reserved / available per location (OSR-01)">
                        <template #actions>
                            <select v-model="balanceFilter" @change="loadStock"
                                class="rounded border border-gray-200 px-2 py-1 text-sm focus:border-op focus:ring-op">
                                <option value="">{{ t('all locations') }}</option>
                                <option v-for="loc in locations" :key="loc.location_id" :value="loc.location_id">{{ loc.name }}</option>
                            </select>
                        </template>
                        <DataTable :columns="balanceColumns" :rows="balances" rowKey="id" :loading="loadingStock" empty="No stock balances.">
                            <template #cell-qty_reserved="{ value }"><span class="text-amber-600">{{ value ?? 0 }}</span></template>
                            <template #cell-available="{ row }">{{ (row.quantity - (row.qty_reserved ?? 0)).toFixed(2) }}</template>
                        </DataTable>
                    </Panel>
                </div>

                <Panel title="Record movement" subtitle="Post a signed stock movement (OSR-02)">
                    <div class="space-y-2">
                        <input v-model="move.sku_id" :placeholder="t('SKU id')" class="w-full rounded border border-gray-200 px-2 py-1 text-sm focus:border-op focus:ring-op" />
                        <select v-model="move.location_id" class="w-full rounded border border-gray-200 px-2 py-1 text-sm focus:border-op focus:ring-op">
                            <option value="">{{ t('— location —') }}</option>
                            <option v-for="loc in locations" :key="loc.location_id" :value="loc.location_id">{{ loc.name }}</option>
                        </select>
                        <div class="flex gap-2">
                            <input v-model="move.quantity" type="number" class="w-24 rounded border border-gray-200 px-2 py-1 text-sm focus:border-op focus:ring-op" />
                            <select v-model="move.reason_code" class="flex-1 rounded border border-gray-200 px-2 py-1 text-sm focus:border-op focus:ring-op">
                                <option v-for="r in reasonCodes" :key="r" :value="r">{{ r }}</option>
                            </select>
                        </div>
                        <input v-model="move.reference" :placeholder="t('reference (WO / PO …)')" class="w-full rounded border border-gray-200 px-2 py-1 text-sm focus:border-op focus:ring-op" />
                        <button @click="postMove" class="w-full rounded bg-op px-3 py-1 text-sm text-white hover:bg-op-dark">{{ t('Post movement') }}</button>
                        <p class="text-xs text-gray-400">{{ t('Signed qty: + inbound, − outbound. Reservations are driven by work orders automatically.') }}</p>
                    </div>
                </Panel>
            </div>

            <!-- PURCHASE ORDERS -->
            <Panel v-else-if="tab === 'pos'" title="Purchase orders" subtitle="Procurement with approval & receipt (OSR-02)">
                <DataTable :columns="poColumns" :rows="pos" rowKey="po_id" :loading="loadingPos" empty="No purchase orders.">
                    <template #cell-id="{ row }">{{ row.po_id ?? row.id }}</template>
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-vendor="{ row }">{{ row.vendor_name ?? row.vendor ?? '—' }}</template>
                    <template #row-actions="{ row }">
                        <button v-if="['DRAFT', 'PENDING_APPROVAL', 'PENDING'].includes(row.status)" @click="poAction(row, 'approve')"
                            class="rounded bg-op px-2 py-0.5 text-xs text-white hover:bg-op-dark">{{ t('Approve') }}</button>
                        <button v-if="row.status === 'APPROVED'" @click="poAction(row, 'receive')"
                            class="ml-1 rounded bg-emerald-600 px-2 py-0.5 text-xs text-white hover:bg-emerald-700">{{ t('Receive') }}</button>
                    </template>
                </DataTable>
            </Panel>

            <!-- EQUIPMENT -->
            <div v-else-if="tab === 'equipment'" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <Panel title="Serialized equipment" subtitle="Instance lifecycle & transitions (OSR-INSTANCE)">
                        <DataTable :columns="equipmentColumns" :rows="equipment" rowKey="instance_id" :loading="loadingEquipment" empty="No equipment instances.">
                            <template #cell-state="{ value }"><StatusBadge :status="value" /></template>
                            <template #cell-subscription_id="{ value }">{{ value ?? '—' }}</template>
                            <template #cell-transition="{ row }">
                                <select @change="transition(row, $event.target.value); $event.target.value = ''"
                                    class="rounded border border-gray-200 px-1 py-0.5 text-xs focus:border-op focus:ring-op">
                                    <option value="">{{ t('→ move to…') }}</option>
                                    <option v-for="s in nextStates[row.state] ?? []" :key="s" :value="s">{{ s }}</option>
                                </select>
                            </template>
                        </DataTable>
                    </Panel>
                </div>
                <Panel title="Lifecycle" subtitle="The chain an instance walks through">
                    <Timeline :events="lifecycleEvents" />
                </Panel>
            </div>

            <!-- SWAPS / RMA -->
            <Panel v-else title="Swap requests / RMA" subtitle="Field swaps & returns (OSR-RMA)">
                <DataTable :columns="swapColumns" :rows="swaps" rowKey="swap_id" :loading="loadingSwaps" empty="No swap requests.">
                    <template #cell-kind="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-context="{ row }">{{ row.subscription_id ?? '—' }} · {{ row.source_instance_id ?? '—' }}</template>
                </DataTable>
            </Panel>
        </div>
    </AuthenticatedLayout>
</template>
