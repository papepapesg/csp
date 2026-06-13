<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

// Catalog Setup (PLM-CFG-01/02/03/07, SIP-01/02). One operator-facing surface to set up the
// product catalogs: services, packages + their launch lifecycle, tax rules/groups, wallets,
// and voice tariffs. Every panel reads/writes the owning catalog API; this page holds no
// business state. All copy flows through the i18n catalog (t()).
const { t, money } = useI18n();

const tab = ref('packages');
const tabs = [
    ['packages', 'Packages'], ['services', 'Services'], ['tax', 'Tax'],
    ['wallets', 'Wallets'], ['voice', 'Voice tariffs'],
];

const notice = ref(null);
const error = ref(null);
const flash = (msg) => { notice.value = msg; setTimeout(() => (notice.value = null), 4000); };
const fail = (e) => { error.value = e.response?.data?.message ?? t('Request failed'); setTimeout(() => (error.value = null), 6000); };

// --- Services (PLM-CFG-01) ---
const services = ref([]);
const newService = ref({ code: '', name: '' });
// The API helpers return either {data:[...]} (paginated) or {items:[...]}/{data:{...}} shapes — read once, normalize.
const rows = (d, ...keys) => { for (const k of keys) { const v = k.split('.').reduce((o, p) => o?.[p], d); if (Array.isArray(v)) return v; } return []; };
async function loadServices() { services.value = rows((await window.axios.get('/api/services')).data, 'items', 'data'); }
async function createService() {
    try {
        await window.axios.post('/api/services', newService.value, { headers: { 'Idempotency-Key': 'svc-' + Date.now() } });
        newService.value = { code: '', name: '' }; flash(t('Service created')); await loadServices();
    } catch (e) { fail(e); }
}

// --- Packages + launch lifecycle (SIP-01/02) ---
const packages = ref([]);
const newPackage = ref({ code: '', name: '', billing_frequency_days: 30 });
const launchPlans = ref([]);
const available = ref([]);
async function loadPackages() { packages.value = rows((await window.axios.get('/api/packages')).data, 'data', 'items'); }
async function loadLaunchPlans() { launchPlans.value = rows((await window.axios.get('/api/package-launch-plans')).data, 'items', 'data'); }
async function loadAvailable() { const d = (await window.axios.get('/api/packages/available')).data; available.value = d.packages ?? d.data?.packages ?? []; }
async function createPackage() {
    try {
        await window.axios.post('/api/packages', newPackage.value, { headers: { 'Idempotency-Key': 'pkg-' + Date.now() } });
        newPackage.value = { code: '', name: '', billing_frequency_days: 30 }; flash(t('Package created')); await loadPackages();
    } catch (e) { fail(e); }
}
async function planAction(plan, action) {
    try { await window.axios.post(`/api/package-launch-plans/${plan.launch_plan_id}/${action}`, {}); flash(t('Done')); await loadLaunchPlans(); await loadAvailable(); }
    catch (e) { fail(e); }
}

// --- Tax (PLM-CFG-02) ---
const taxRules = ref([]);
const taxGroups = ref([]);
const newRule = ref({ code: '', name: '', rate: 0.16, base_method: 'BASE' });
async function loadTax() {
    taxRules.value = (await window.axios.get('/api/tax/rules')).data.items ?? [];
    taxGroups.value = (await window.axios.get('/api/tax/groups')).data.items ?? [];
}
async function createRule() {
    try { await window.axios.post('/api/tax/rules', newRule.value); newRule.value = { code: '', name: '', rate: 0.16, base_method: 'BASE' }; flash(t('Tax rule saved')); await loadTax(); }
    catch (e) { fail(e); }
}

// --- Wallets (PLM-CFG-03) ---
const wallets = ref([]);
const newWallet = ref({ code: '', description: '', wallet_type_code: 'MONEY', currency: 'KES', applicability: 'ANY' });
async function loadWallets() { wallets.value = rows((await window.axios.get('/api/wallet-catalog')).data, 'data', 'items'); }
async function createWallet() {
    try { await window.axios.post('/api/wallet-catalog', newWallet.value, { headers: { 'Idempotency-Key': 'wal-' + Date.now() } }); newWallet.value = { code: '', description: '', wallet_type_code: 'MONEY', currency: 'KES', applicability: 'ANY' }; flash(t('Wallet created')); await loadWallets(); }
    catch (e) { fail(e); }
}
async function walletAction(w, action) { try { await window.axios.post(`/api/wallet-catalog/${w.wallet_catalog_id}/${action}`); flash(t('Done')); await loadWallets(); } catch (e) { fail(e); } }

// --- Voice tariffs (PLM-CFG-07) ---
const voicePlans = ref([]);
const newVoicePlan = ref({ tariff_plan_code: '', display_name: '', billing_mode: 'BOTH', currency_code: 'KES' });
async function loadVoice() { voicePlans.value = rows((await window.axios.get('/api/plm/voice-tariff-plans')).data, 'items', 'data'); }
async function createVoicePlan() {
    try { await window.axios.post('/api/plm/voice-tariff-plans', newVoicePlan.value, { headers: { 'Idempotency-Key': 'vtp-' + Date.now() } }); newVoicePlan.value = { tariff_plan_code: '', display_name: '', billing_mode: 'BOTH', currency_code: 'KES' }; flash(t('Voice plan created')); await loadVoice(); }
    catch (e) { fail(e); }
}
async function voicePlanAction(p, action) { try { await window.axios.post(`/api/plm/voice-tariff-plans/${p.tariff_plan_id}/${action}`); flash(t('Done')); await loadVoice(); } catch (e) { fail(e); } }

// summary tiles per tab — counts straight off the loaded read models.
const activeCount = (list) => list.filter((x) => x.status === 'ACTIVE').length;

function loadTab() {
    const loaders = { packages: async () => { await loadPackages(); await loadLaunchPlans(); await loadAvailable(); }, services: loadServices, tax: loadTax, wallets: loadWallets, voice: loadVoice };
    (loaders[tab.value] ?? (() => {}))().catch(fail);
}
function selectTab(key) { tab.value = key; loadTab(); }
onMounted(loadTab);

const taxColumns = [
    { key: 'code', label: 'Code' }, { key: 'rate', label: 'Rate' },
    { key: 'base_method', label: 'Base' }, { key: 'effective_from', label: 'From' }, { key: 'effective_until', label: 'Until' },
];
</script>

<template>
    <Head :title="t('Catalog Setup')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader :title="t('Catalog')" :crumbs="[{ label: 'Catalog' }, { label: 'Setup' }]" />
        </template>

        <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <p v-if="notice" class="rounded-lg bg-emerald-50 p-2 text-sm text-emerald-700 ring-1 ring-emerald-100">{{ notice }}</p>
            <p v-if="error" class="rounded-lg bg-red-50 p-2 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</p>

            <!-- segmented tabs -->
            <div class="inline-flex flex-wrap gap-1 rounded-xl bg-gray-100 p-1">
                <button v-for="[key, label] in tabs" :key="key" @click="selectTab(key)"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                    :class="tab === key ? 'bg-white text-op shadow-sm' : 'text-gray-500 hover:text-gray-700'">{{ t(label) }}</button>
            </div>

            <!-- PACKAGES -->
            <div v-if="tab === 'packages'" class="space-y-5">
                <div class="grid grid-cols-3 gap-4">
                    <StatCard :label="t('Packages')" :value="packages.length" tone="indigo" :sub="t(':n active', { n: activeCount(packages) })" />
                    <StatCard :label="t('Sellable now')" :value="available.length" tone="emerald" />
                    <StatCard :label="t('In launch pipeline')" :value="launchPlans.length" :tone="launchPlans.length ? 'amber' : 'gray'" />
                </div>

                <Panel :title="t('New package (SIP-01)')">
                    <div class="flex flex-wrap gap-2">
                        <input v-model="newPackage.code" :placeholder="t('Package code')" class="rounded-md border-gray-300 text-sm" />
                        <input v-model="newPackage.name" :placeholder="t('Display name')" class="flex-1 rounded-md border-gray-300 text-sm" />
                        <input v-model.number="newPackage.billing_frequency_days" type="number" :placeholder="t('Billing days')" class="w-28 rounded-md border-gray-300 text-sm" />
                        <button @click="createPackage" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ t('Create') }}</button>
                    </div>
                </Panel>

                <div class="grid grid-cols-12 gap-5">
                    <div class="col-span-12 lg:col-span-6">
                        <Panel :title="t('Packages')">
                            <DataTable :columns="[{ key: 'code', label: 'Code' }, { key: 'name', label: 'Name' }, { key: 'status', label: 'Status' }]" :rows="packages" row-key="id" empty="No packages yet.">
                                <template #cell-code="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                                <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                            </DataTable>
                        </Panel>
                    </div>
                    <div class="col-span-12 lg:col-span-6">
                        <Panel :title="t('Launch pipeline (SIP-02)')">
                            <div v-for="lp in launchPlans" :key="lp.launch_plan_id" class="border-b border-gray-50 py-2 last:border-0">
                                <div class="flex items-center justify-between">
                                    <span class="font-mono text-xs">{{ lp.package_code }}</span>
                                    <StatusBadge :status="lp.status" />
                                </div>
                                <div class="mt-1 flex gap-1">
                                    <button @click="planAction(lp, 'validate')" class="rounded border px-2 py-0.5 text-xs hover:bg-gray-50">{{ t('Validate') }}</button>
                                    <button @click="planAction(lp, 'submit-review')" class="rounded border px-2 py-0.5 text-xs hover:bg-gray-50">{{ t('Submit review') }}</button>
                                    <button @click="planAction(lp, 'activate')" class="rounded border px-2 py-0.5 text-xs hover:bg-gray-50">{{ t('Activate') }}</button>
                                </div>
                            </div>
                            <p v-if="!launchPlans.length" class="text-sm text-gray-400">{{ t('No launch plans.') }}</p>
                        </Panel>
                    </div>
                </div>

                <Panel :title="t('Sellable packages (read model)')">
                    <DataTable :columns="[{ key: 'packageCode', label: 'Code' }, { key: 'displayName', label: 'Name' }, { key: 'price', label: 'Price', align: 'right' }]" :rows="available" row-key="packageVersionId" empty="No sellable packages.">
                        <template #cell-packageCode="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                        <template #cell-price="{ value }"><span class="tabular-nums">{{ money(value) }}</span></template>
                    </DataTable>
                </Panel>
            </div>

            <!-- SERVICES -->
            <div v-if="tab === 'services'" class="space-y-5">
                <div class="grid grid-cols-3 gap-4">
                    <StatCard :label="t('Services')" :value="services.length" tone="indigo" :sub="t(':n active', { n: activeCount(services) })" />
                </div>
                <Panel :title="t('Services (PLM-CFG-01)')">
                    <div class="mb-3 flex gap-2">
                        <input v-model="newService.code" :placeholder="t('Service code')" class="rounded-md border-gray-300 text-sm" />
                        <input v-model="newService.name" :placeholder="t('Service name')" class="flex-1 rounded-md border-gray-300 text-sm" />
                        <button @click="createService" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ t('Create') }}</button>
                    </div>
                    <DataTable :columns="[{ key: 'code', label: 'Code' }, { key: 'name', label: 'Name' }, { key: 'status', label: 'Status' }]" :rows="services" row-key="id" empty="No services yet.">
                        <template #cell-code="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                        <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    </DataTable>
                </Panel>
            </div>

            <!-- TAX -->
            <div v-if="tab === 'tax'" class="grid grid-cols-12 gap-5">
                <div class="col-span-12 lg:col-span-7">
                    <Panel :title="t('Tax rules (effective-dated versions)')">
                        <div class="mb-3 flex flex-wrap gap-2">
                            <input v-model="newRule.code" :placeholder="t('Rule code')" class="rounded-md border-gray-300 text-sm" />
                            <input v-model="newRule.name" :placeholder="t('Rule name')" class="flex-1 rounded-md border-gray-300 text-sm" />
                            <input v-model.number="newRule.rate" type="number" step="0.0001" :placeholder="t('Rate (0–1)')" class="w-24 rounded-md border-gray-300 text-sm" />
                            <select v-model="newRule.base_method" class="rounded-md border-gray-300 text-sm">
                                <option value="BASE">{{ t('BASE') }}</option>
                                <option value="BASE_PLUS_PRIOR">{{ t('BASE_PLUS_PRIOR') }}</option>
                            </select>
                            <button @click="createRule" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ t('Save') }}</button>
                        </div>
                        <DataTable :columns="taxColumns" :rows="taxRules" row-key="tax_rule_id" empty="No tax rules.">
                            <template #cell-code="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                        </DataTable>
                    </Panel>
                </div>
                <div class="col-span-12 lg:col-span-5">
                    <Panel :title="t('Tax groups')">
                        <div v-for="g in taxGroups" :key="g.tax_group_id" class="border-b border-gray-50 py-1.5 text-sm last:border-0">
                            <span class="font-mono text-xs">{{ g.code }}</span>
                            <span class="ml-2 text-xs text-gray-400">{{ (g.order_within_group ?? []).join(' → ') }}</span>
                        </div>
                        <p v-if="!taxGroups.length" class="text-sm text-gray-400">{{ t('No tax groups.') }}</p>
                    </Panel>
                </div>
            </div>

            <!-- WALLETS -->
            <div v-if="tab === 'wallets'">
                <Panel :title="t('Wallet catalog (PLM-CFG-03)')">
                    <div class="mb-3 flex flex-wrap gap-2">
                        <input v-model="newWallet.code" :placeholder="t('Wallet code')" class="rounded-md border-gray-300 text-sm" />
                        <input v-model="newWallet.description" :placeholder="t('Description')" class="flex-1 rounded-md border-gray-300 text-sm" />
                        <input v-model="newWallet.wallet_type_code" :placeholder="t('Type')" class="w-28 rounded-md border-gray-300 text-sm" />
                        <input v-model="newWallet.currency" :placeholder="t('Currency')" class="w-20 rounded-md border-gray-300 text-sm" />
                        <select v-model="newWallet.applicability" class="rounded-md border-gray-300 text-sm">
                            <option value="ANY">{{ t('ANY') }}</option>
                            <option value="PREPAID_ONLY">{{ t('PREPAID_ONLY') }}</option>
                            <option value="POSTPAID_ONLY">{{ t('POSTPAID_ONLY') }}</option>
                        </select>
                        <button @click="createWallet" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ t('Create') }}</button>
                    </div>
                    <DataTable :columns="[{ key: 'code', label: 'Code' }, { key: 'description', label: 'Description' }, { key: 'status', label: 'Status' }]" :rows="wallets" row-key="wallet_catalog_id" empty="No wallets yet.">
                        <template #cell-code="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                        <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                        <template #row-actions="{ row }">
                            <button v-if="row.status === 'DRAFT'" @click="walletAction(row, 'activate')" class="rounded border px-2 py-0.5 text-xs hover:bg-gray-50">{{ t('Activate') }}</button>
                            <button v-else-if="row.status === 'ACTIVE'" @click="walletAction(row, 'retire')" class="rounded border px-2 py-0.5 text-xs hover:bg-gray-50">{{ t('Retire') }}</button>
                        </template>
                    </DataTable>
                </Panel>
            </div>

            <!-- VOICE TARIFFS -->
            <div v-if="tab === 'voice'">
                <Panel :title="t('Voice tariff plans (PLM-CFG-07)')">
                    <div class="mb-3 flex flex-wrap gap-2">
                        <input v-model="newVoicePlan.tariff_plan_code" :placeholder="t('Plan code')" class="rounded-md border-gray-300 text-sm" />
                        <input v-model="newVoicePlan.display_name" :placeholder="t('Display name')" class="flex-1 rounded-md border-gray-300 text-sm" />
                        <select v-model="newVoicePlan.billing_mode" class="rounded-md border-gray-300 text-sm">
                            <option value="BOTH">{{ t('BOTH') }}</option>
                            <option value="PREPAID">{{ t('PREPAID') }}</option>
                            <option value="POSTPAID">{{ t('POSTPAID') }}</option>
                        </select>
                        <input v-model="newVoicePlan.currency_code" :placeholder="t('Currency')" class="w-20 rounded-md border-gray-300 text-sm" />
                        <button @click="createVoicePlan" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ t('Create') }}</button>
                    </div>
                    <DataTable :columns="[{ key: 'tariff_plan_code', label: 'Code' }, { key: 'display_name', label: 'Name' }, { key: 'status', label: 'Status' }]" :rows="voicePlans" row-key="tariff_plan_id" empty="No voice tariff plans.">
                        <template #cell-tariff_plan_code="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                        <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                        <template #row-actions="{ row }">
                            <button v-if="row.status === 'DRAFT'" @click="voicePlanAction(row, 'activate')" class="rounded border px-2 py-0.5 text-xs hover:bg-gray-50">{{ t('Activate') }}</button>
                            <button v-else-if="row.status === 'ACTIVE'" @click="voicePlanAction(row, 'retire')" class="rounded border px-2 py-0.5 text-xs hover:bg-gray-50">{{ t('Retire') }}</button>
                        </template>
                    </DataTable>
                </Panel>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
