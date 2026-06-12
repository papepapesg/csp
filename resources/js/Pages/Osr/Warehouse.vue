<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();

// Warehouse backoffice (OSR-01/02/05 + OSR-INSTANCE/RMA). The services were already
// there — stock chain with reservations, procurement with approval, counts,
// serialized instances, swaps; this is their operations UI.
const tab = ref('stock'); // stock | pos | equipment | swaps
const error = ref(null);
const notice = ref(null);
const flash = (e) => { error.value = e.response?.data?.message ?? t('Request failed'); setTimeout(() => (error.value = null), 6000); };

const locations = ref([]);
const balances = ref([]);
const balanceFilter = ref('');
const move = ref({ sku_id: '', location_id: '', quantity: 1, reason_code: 'RECEIPT', reference: '' });

const pos = ref([]);
const equipment = ref([]);
const swaps = ref([]);

async function loadStock() {
    const l = await window.axios.get('/api/stock-locations', { params: { size: 100 } });
    locations.value = l.data.items ?? [];
    const b = await window.axios.get('/api/stock-balances', { params: { location: balanceFilter.value || undefined } });
    balances.value = b.data.items ?? [];
}
async function postMove() {
    try {
        await window.axios.post('/api/stock-movements', { ...move.value, quantity: Number(move.value.quantity) }, { headers: { 'Idempotency-Key': `mv-${Date.now()}` } });
        notice.value = t('Movement recorded'); await loadStock();
    } catch (e) { flash(e); }
}
async function loadPos() {
    const { data } = await window.axios.get('/api/purchase-orders', { params: { size: 50 } });
    pos.value = data.items ?? data.content ?? [];
}
async function poAction(po, action) {
    try { await window.axios.post(`/api/purchase-orders/${po.po_id ?? po.purchase_order_id ?? po.id}/${action}`); await loadPos(); notice.value = `PO ${action}d`; } catch (e) { flash(e); }
}
async function loadEquipment() {
    const { data } = await window.axios.get('/api/equipment-instances', { params: { size: 50 } });
    equipment.value = data.items ?? data.content ?? [];
}
async function transition(inst, toState) {
    try { await window.axios.post(`/api/equipment-instances/${inst.instance_id}/transition`, { state: toState }); await loadEquipment(); } catch (e) { flash(e); }
}
async function loadSwaps() {
    const { data } = await window.axios.get('/api/swap-requests', { params: { size: 50 } });
    swaps.value = data.items ?? data.content ?? [];
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

onMounted(() => { loadStock(); loadPos(); loadEquipment(); loadSwaps(); });
</script>

<template>
    <Head :title="t('Warehouse')" />
    <AuthenticatedLayout>
        <template #header><h2 class="font-semibold text-xl text-gray-800">{{ t('Warehouse — stock, procurement & equipment') }}</h2></template>

        <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4 flex gap-2">
                <button v-for="tb in [['stock', 'Stock chain'], ['pos', 'Purchase orders'], ['equipment', 'Equipment'], ['swaps', 'Swaps / RMA']]" :key="tb[0]"
                    @click="tab = tb[0]" :class="tab === tb[0] ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-1 rounded border text-sm">{{ t(tb[1]) }}</button>
            </div>
            <div v-if="error" class="mb-3 p-2 bg-red-100 text-red-700 rounded text-sm">{{ error }}</div>
            <div v-if="notice" class="mb-3 p-2 bg-green-100 text-green-700 rounded text-sm">{{ notice }}</div>

            <!-- Stock chain -->
            <div v-if="tab === 'stock'" class="grid grid-cols-12 gap-4">
                <div class="col-span-8 bg-white rounded shadow p-4">
                    <div class="flex gap-2 mb-2">
                        <select v-model="balanceFilter" @change="loadStock" class="border rounded px-2 py-1 text-sm">
                            <option value="">{{ t('all locations') }}</option>
                            <option v-for="l in locations" :key="l.location_id" :value="l.location_id">{{ l.name }}</option>
                        </select>
                    </div>
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500 uppercase"><th class="py-1">{{ t('Location') }}</th><th>{{ t('SKU') }}</th><th class="text-right">{{ t('On hand') }}</th><th class="text-right">{{ t('Reserved') }}</th><th class="text-right">{{ t('Available') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="b in balances" :key="b.id" class="border-t">
                                <td class="py-1 text-xs">{{ b.location_id }}</td>
                                <td class="font-mono text-xs">{{ b.sku_id }}</td>
                                <td class="text-right">{{ b.quantity }}</td>
                                <td class="text-right text-amber-600">{{ b.qty_reserved ?? 0 }}</td>
                                <td class="text-right font-semibold">{{ (b.quantity - (b.qty_reserved ?? 0)).toFixed(2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-span-4 bg-white rounded shadow p-4 space-y-2">
                    <div class="font-semibold text-sm">{{ t('Record movement') }}</div>
                    <input v-model="move.sku_id" :placeholder="t('SKU id')" class="border rounded px-2 py-1 text-sm w-full" />
                    <select v-model="move.location_id" class="border rounded px-2 py-1 text-sm w-full">
                        <option value="">{{ t('— location —') }}</option>
                        <option v-for="l in locations" :key="l.location_id" :value="l.location_id">{{ l.name }}</option>
                    </select>
                    <div class="flex gap-2">
                        <input v-model="move.quantity" type="number" class="border rounded px-2 py-1 text-sm w-24" />
                        <select v-model="move.reason_code" class="border rounded px-2 py-1 text-sm flex-1">
                            <option v-for="r in ['RECEIPT', 'ISSUE', 'TRANSFER_IN', 'TRANSFER_OUT', 'INSTALL', 'RETURN', 'ADJUST']" :key="r">{{ r }}</option>
                        </select>
                    </div>
                    <input v-model="move.reference" :placeholder="t('reference (WO / PO …)')" class="border rounded px-2 py-1 text-sm w-full" />
                    <button @click="postMove" class="px-3 py-1 bg-indigo-600 text-white rounded text-sm w-full">{{ t('Post movement') }}</button>
                    <p class="text-xs text-gray-400">{{ t('Signed qty: + inbound, − outbound. Reservations are driven by work orders automatically.') }}</p>
                </div>
            </div>

            <!-- Purchase orders -->
            <div v-else-if="tab === 'pos'" class="bg-white rounded shadow divide-y">
                <div v-for="po in pos" :key="po.po_id ?? po.id" class="p-3 flex justify-between items-center">
                    <div>
                        <span class="font-mono text-xs">{{ po.po_id ?? po.id }}</span>
                        <span class="ml-2 text-xs px-1.5 py-0.5 rounded bg-gray-100">{{ po.status }}</span>
                        <span class="ml-2 text-xs text-gray-400">{{ po.vendor_name ?? po.vendor ?? '' }}</span>
                    </div>
                    <div class="flex gap-1">
                        <button v-if="['DRAFT', 'PENDING_APPROVAL', 'PENDING'].includes(po.status)" @click="poAction(po, 'approve')" class="px-2 py-0.5 bg-indigo-600 text-white rounded text-xs">{{ t('Approve') }}</button>
                        <button v-if="po.status === 'APPROVED'" @click="poAction(po, 'receive')" class="px-2 py-0.5 bg-green-600 text-white rounded text-xs">{{ t('Receive') }}</button>
                    </div>
                </div>
                <div v-if="!pos.length" class="p-3 text-sm text-gray-400">{{ t('No purchase orders.') }}</div>
            </div>

            <!-- Equipment -->
            <div v-else-if="tab === 'equipment'" class="bg-white rounded shadow p-4">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500 uppercase"><th class="py-1">{{ t('Serial') }}</th><th>{{ t('SKU') }}</th><th>{{ t('State') }}</th><th>{{ t('Subscription') }}</th><th>{{ t('Transition') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="e in equipment" :key="e.instance_id" class="border-t">
                            <td class="py-1 font-mono text-xs">{{ e.serial }}</td>
                            <td class="font-mono text-xs">{{ e.sku_id }}</td>
                            <td><span class="text-xs px-1.5 py-0.5 rounded" :class="e.state === 'IN_FIELD_ACTIVE' ? 'bg-green-100 text-green-700' : 'bg-gray-100'">{{ e.state }}</span></td>
                            <td class="text-xs">{{ e.subscription_id ?? '—' }}</td>
                            <td>
                                <select @change="transition(e, $event.target.value); $event.target.value = ''" class="border rounded px-1 py-0.5 text-xs">
                                    <option value="">{{ t('→ move to…') }}</option>
                                    <option v-for="s in nextStates[e.state] ?? []" :key="s">{{ s }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Swaps -->
            <div v-else class="bg-white rounded shadow divide-y">
                <div v-for="s in swaps" :key="s.swap_id" class="p-3">
                    <span class="font-mono text-xs">{{ s.swap_id }}</span>
                    <span class="ml-2 text-xs px-1.5 py-0.5 rounded bg-gray-100">{{ s.kind }}</span>
                    <span class="ml-2 text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-700">{{ s.status }}</span>
                    <span class="ml-2 text-xs text-gray-400">{{ s.subscription_id ?? '' }} · {{ s.source_instance_id ?? '' }}</span>
                </div>
                <div v-if="!swaps.length" class="p-3 text-sm text-gray-400">{{ t('No swap requests.') }}</div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
