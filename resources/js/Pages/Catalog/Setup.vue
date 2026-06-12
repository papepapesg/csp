<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
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

const badge = (s) => ({
    ACTIVE: 'bg-green-100 text-green-700', DRAFT: 'bg-gray-100 text-gray-600', READY_FOR_REVIEW: 'bg-blue-100 text-blue-700',
    PENDING_APPROVAL: 'bg-amber-100 text-amber-700', APPROVED: 'bg-indigo-100 text-indigo-700', SCHEDULED: 'bg-cyan-100 text-cyan-700',
    REJECTED: 'bg-red-100 text-red-600', SUSPENDED: 'bg-amber-100 text-amber-700', END_OF_SALE: 'bg-orange-100 text-orange-700',
    RETIRED: 'bg-red-50 text-red-500',
}[s] ?? 'bg-gray-100 text-gray-600');

function loadTab() {
    const loaders = { packages: async () => { await loadPackages(); await loadLaunchPlans(); await loadAvailable(); }, services: loadServices, tax: loadTax, wallets: loadWallets, voice: loadVoice };
    (loaders[tab.value] ?? (() => {}))().catch(fail);
}
function selectTab(key) { tab.value = key; loadTab(); }
onMounted(loadTab);
</script>

<template>
    <Head :title="t('Catalog Setup')" />
    <AuthenticatedLayout>
        <template #header><h2 class="text-xl font-semibold text-gray-800">{{ t('Catalog Setup') }}</h2></template>

        <div class="py-6 mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-4">
            <p v-if="notice" class="p-2 bg-green-100 text-green-700 rounded text-sm">{{ notice }}</p>
            <p v-if="error" class="p-2 bg-red-100 text-red-700 rounded text-sm">{{ error }}</p>

            <div class="flex gap-2 flex-wrap">
                <button v-for="[key, label] in tabs" :key="key" @click="selectTab(key)"
                    :class="tab === key ? 'bg-indigo-600 text-white' : 'bg-white'"
                    class="px-3 py-1 rounded border text-sm">{{ t(label) }}</button>
            </div>

            <!-- PACKAGES -->
            <div v-if="tab === 'packages'" class="space-y-4">
                <div class="bg-white rounded shadow p-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase mb-2">{{ t('New package (SIP-01)') }}</div>
                    <div class="flex gap-2 flex-wrap">
                        <input v-model="newPackage.code" :placeholder="t('Package code')" class="border rounded px-2 py-1 text-sm" />
                        <input v-model="newPackage.name" :placeholder="t('Display name')" class="border rounded px-2 py-1 text-sm flex-1" />
                        <input v-model.number="newPackage.billing_frequency_days" type="number" :placeholder="t('Billing days')" class="border rounded px-2 py-1 text-sm w-28" />
                        <button @click="createPackage" class="px-3 py-1 bg-indigo-600 text-white rounded text-sm">{{ t('Create') }}</button>
                    </div>
                </div>

                <div class="grid grid-cols-12 gap-4">
                    <div class="col-span-6 bg-white rounded shadow p-4">
                        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">{{ t('Packages') }}</div>
                        <div v-for="p in packages" :key="p.id" class="flex justify-between items-center text-sm border-t py-1">
                            <span class="font-mono text-xs">{{ p.code }}</span>
                            <span class="flex-1 px-2 truncate">{{ p.name }}</span>
                            <span class="text-xs px-1.5 py-0.5 rounded" :class="badge(p.status)">{{ p.status }}</span>
                        </div>
                        <div v-if="!packages.length" class="text-sm text-gray-400">{{ t('No packages yet.') }}</div>
                    </div>

                    <div class="col-span-6 bg-white rounded shadow p-4">
                        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">{{ t('Launch pipeline (SIP-02)') }}</div>
                        <div v-for="lp in launchPlans" :key="lp.launch_plan_id" class="text-sm border-t py-1">
                            <div class="flex justify-between items-center">
                                <span class="font-mono text-xs">{{ lp.package_code }}</span>
                                <span class="text-xs px-1.5 py-0.5 rounded" :class="badge(lp.status)">{{ lp.status }}</span>
                            </div>
                            <div class="flex gap-1 mt-1">
                                <button @click="planAction(lp, 'validate')" class="text-xs px-2 py-0.5 border rounded">{{ t('Validate') }}</button>
                                <button @click="planAction(lp, 'submit-review')" class="text-xs px-2 py-0.5 border rounded">{{ t('Submit review') }}</button>
                                <button @click="planAction(lp, 'activate')" class="text-xs px-2 py-0.5 border rounded">{{ t('Activate') }}</button>
                            </div>
                        </div>
                        <div v-if="!launchPlans.length" class="text-sm text-gray-400">{{ t('No launch plans.') }}</div>
                    </div>
                </div>

                <div class="bg-white rounded shadow p-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase mb-2">{{ t('Sellable packages (read model)') }}</div>
                    <div v-for="a in available" :key="a.packageVersionId" class="flex justify-between text-sm border-t py-1">
                        <span class="font-mono text-xs">{{ a.packageCode }}</span>
                        <span class="flex-1 px-2 truncate">{{ a.displayName }}</span>
                        <span>{{ money(a.price) }}</span>
                    </div>
                    <div v-if="!available.length" class="text-sm text-gray-400">{{ t('No sellable packages.') }}</div>
                </div>
            </div>

            <!-- SERVICES -->
            <div v-if="tab === 'services'" class="bg-white rounded shadow p-4 space-y-3">
                <div class="flex gap-2">
                    <input v-model="newService.code" :placeholder="t('Service code')" class="border rounded px-2 py-1 text-sm" />
                    <input v-model="newService.name" :placeholder="t('Service name')" class="border rounded px-2 py-1 text-sm flex-1" />
                    <button @click="createService" class="px-3 py-1 bg-indigo-600 text-white rounded text-sm">{{ t('Create') }}</button>
                </div>
                <div v-for="s in services" :key="s.id" class="flex justify-between text-sm border-t py-1">
                    <span class="font-mono text-xs">{{ s.code }}</span>
                    <span class="flex-1 px-2 truncate">{{ s.name }}</span>
                    <span class="text-xs px-1.5 py-0.5 rounded" :class="badge(s.status)">{{ s.status }}</span>
                </div>
                <div v-if="!services.length" class="text-sm text-gray-400">{{ t('No services yet.') }}</div>
            </div>

            <!-- TAX -->
            <div v-if="tab === 'tax'" class="grid grid-cols-12 gap-4">
                <div class="col-span-7 bg-white rounded shadow p-4 space-y-3">
                    <div class="text-xs font-semibold text-gray-500 uppercase">{{ t('Tax rules (effective-dated versions)') }}</div>
                    <div class="flex gap-2 flex-wrap">
                        <input v-model="newRule.code" :placeholder="t('Rule code')" class="border rounded px-2 py-1 text-sm" />
                        <input v-model="newRule.name" :placeholder="t('Rule name')" class="border rounded px-2 py-1 text-sm flex-1" />
                        <input v-model.number="newRule.rate" type="number" step="0.0001" :placeholder="t('Rate (0–1)')" class="border rounded px-2 py-1 text-sm w-24" />
                        <select v-model="newRule.base_method" class="border rounded px-2 py-1 text-sm">
                            <option value="BASE">{{ t('BASE') }}</option>
                            <option value="BASE_PLUS_PRIOR">{{ t('BASE_PLUS_PRIOR') }}</option>
                        </select>
                        <button @click="createRule" class="px-3 py-1 bg-indigo-600 text-white rounded text-sm">{{ t('Save') }}</button>
                    </div>
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500 uppercase"><th class="py-1">{{ t('Code') }}</th><th>{{ t('Rate') }}</th><th>{{ t('Base') }}</th><th>{{ t('From') }}</th><th>{{ t('Until') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="r in taxRules" :key="r.tax_rule_id" class="border-t">
                                <td class="py-1 font-mono text-xs">{{ r.code }}</td>
                                <td>{{ r.rate }}</td>
                                <td class="text-xs">{{ r.base_method }}</td>
                                <td class="text-xs">{{ r.effective_from ?? '—' }}</td>
                                <td class="text-xs">{{ r.effective_until ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-span-5 bg-white rounded shadow p-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase mb-2">{{ t('Tax groups') }}</div>
                    <div v-for="g in taxGroups" :key="g.tax_group_id" class="text-sm border-t py-1">
                        <span class="font-mono text-xs">{{ g.code }}</span>
                        <span class="text-xs text-gray-400 ml-2">{{ (g.order_within_group ?? []).join(' → ') }}</span>
                    </div>
                    <div v-if="!taxGroups.length" class="text-sm text-gray-400">{{ t('No tax groups.') }}</div>
                </div>
            </div>

            <!-- WALLETS -->
            <div v-if="tab === 'wallets'" class="bg-white rounded shadow p-4 space-y-3">
                <div class="flex gap-2 flex-wrap">
                    <input v-model="newWallet.code" :placeholder="t('Wallet code')" class="border rounded px-2 py-1 text-sm" />
                    <input v-model="newWallet.description" :placeholder="t('Description')" class="border rounded px-2 py-1 text-sm flex-1" />
                    <input v-model="newWallet.wallet_type_code" :placeholder="t('Type')" class="border rounded px-2 py-1 text-sm w-28" />
                    <input v-model="newWallet.currency" :placeholder="t('Currency')" class="border rounded px-2 py-1 text-sm w-20" />
                    <select v-model="newWallet.applicability" class="border rounded px-2 py-1 text-sm">
                        <option value="ANY">{{ t('ANY') }}</option>
                        <option value="PREPAID_ONLY">{{ t('PREPAID_ONLY') }}</option>
                        <option value="POSTPAID_ONLY">{{ t('POSTPAID_ONLY') }}</option>
                    </select>
                    <button @click="createWallet" class="px-3 py-1 bg-indigo-600 text-white rounded text-sm">{{ t('Create') }}</button>
                </div>
                <div v-for="w in wallets" :key="w.wallet_catalog_id" class="flex justify-between items-center text-sm border-t py-1">
                    <span class="font-mono text-xs">{{ w.code }}</span>
                    <span class="flex-1 px-2 truncate">{{ w.description }}</span>
                    <span class="text-xs px-1.5 py-0.5 rounded" :class="badge(w.status)">{{ w.status }}</span>
                    <button v-if="w.status === 'DRAFT'" @click="walletAction(w, 'activate')" class="ml-2 text-xs px-2 py-0.5 border rounded">{{ t('Activate') }}</button>
                    <button v-else-if="w.status === 'ACTIVE'" @click="walletAction(w, 'retire')" class="ml-2 text-xs px-2 py-0.5 border rounded">{{ t('Retire') }}</button>
                </div>
                <div v-if="!wallets.length" class="text-sm text-gray-400">{{ t('No wallets yet.') }}</div>
            </div>

            <!-- VOICE TARIFFS -->
            <div v-if="tab === 'voice'" class="bg-white rounded shadow p-4 space-y-3">
                <div class="flex gap-2 flex-wrap">
                    <input v-model="newVoicePlan.tariff_plan_code" :placeholder="t('Plan code')" class="border rounded px-2 py-1 text-sm" />
                    <input v-model="newVoicePlan.display_name" :placeholder="t('Display name')" class="border rounded px-2 py-1 text-sm flex-1" />
                    <select v-model="newVoicePlan.billing_mode" class="border rounded px-2 py-1 text-sm">
                        <option value="BOTH">{{ t('BOTH') }}</option>
                        <option value="PREPAID">{{ t('PREPAID') }}</option>
                        <option value="POSTPAID">{{ t('POSTPAID') }}</option>
                    </select>
                    <input v-model="newVoicePlan.currency_code" :placeholder="t('Currency')" class="border rounded px-2 py-1 text-sm w-20" />
                    <button @click="createVoicePlan" class="px-3 py-1 bg-indigo-600 text-white rounded text-sm">{{ t('Create') }}</button>
                </div>
                <div v-for="p in voicePlans" :key="p.tariff_plan_id" class="flex justify-between items-center text-sm border-t py-1">
                    <span class="font-mono text-xs">{{ p.tariff_plan_code }}</span>
                    <span class="flex-1 px-2 truncate">{{ p.display_name }}</span>
                    <span class="text-xs px-1.5 py-0.5 rounded" :class="badge(p.status)">{{ p.status }}</span>
                    <button v-if="p.status === 'DRAFT'" @click="voicePlanAction(p, 'activate')" class="ml-2 text-xs px-2 py-0.5 border rounded">{{ t('Activate') }}</button>
                    <button v-else-if="p.status === 'ACTIVE'" @click="voicePlanAction(p, 'retire')" class="ml-2 text-xs px-2 py-0.5 border rounded">{{ t('Retire') }}</button>
                </div>
                <div v-if="!voicePlans.length" class="text-sm text-gray-400">{{ t('No voice tariff plans.') }}</div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
