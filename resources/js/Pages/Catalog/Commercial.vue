<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

// Commercial Studio (SIP-04/SIP-05). Design campaigns (offers, targeting, channels,
// window, caps) and drive their lifecycle; compose bundles and walk the launch
// pipeline (validate -> review -> approve -> activate) with the auditable checks.
// Everything here is catalog DATA consumed by order capture, sales and billing.
const tab = ref('campaigns'); // campaigns | bundles
const channels = ['BACKOFFICE', 'SALES_APP', 'SELF_CARE', 'USSD', 'PARTNER_API'];
const campaignTypes = ['ACQUISITION', 'RETENTION', 'UPSELL', 'CROSS_SELL', 'WINBACK', 'RECOVERY', 'LOYALTY', 'STAFF_PARTNER', 'REGIONAL'];
const ruleTypes = ['FRANCHISE', 'REGION', 'PACKAGE', 'CUSTOMER_SEGMENT', 'PAYMENT_STATUS'];

const error = ref(null);
const notice = ref(null);
const flash = (e) => { error.value = e.response?.data?.message ?? 'Request failed'; setTimeout(() => (error.value = null), 6000); };

// --- campaigns ---
const campaigns = ref([]);
const discounts = ref([]);
const showDesigner = ref(false);
const draft = ref(null);
const eligibility = ref({ open: null, context: { channelCode: 'SALES_APP', franchiseId: '', regionCode: '', packageRef: '' }, result: null });

const blankCampaign = () => ({
    code: '', name: '', campaign_type: 'ACQUISITION',
    starts_at: new Date().toISOString().slice(0, 10), ends_at: '', max_participants: null,
    offers: [{ offer_type: 'DISCOUNT', discount_code: '', assignment_scope_type: 'ORDER' }],
    target_rules: [], channels: ['SALES_APP'],
});

async function loadCampaigns() {
    const { data } = await window.axios.get('/api/campaigns', { params: { size: 50 } });
    campaigns.value = data.items ?? [];
    const d = await window.axios.get('/api/discounts', { params: { size: 100 } });
    discounts.value = (d.data.items ?? []).map((x) => x.code);
}
function design() { draft.value = blankCampaign(); showDesigner.value = true; }
function addRule() { draft.value.target_rules.push({ rule_type: 'FRANCHISE', operator: 'IN', valuesCsv: '', hard_exclusion: true }); }
function toggleChannel(c) {
    const i = draft.value.channels.indexOf(c);
    i >= 0 ? draft.value.channels.splice(i, 1) : draft.value.channels.push(c);
}
async function saveCampaign() {
    try {
        const payload = JSON.parse(JSON.stringify(draft.value));
        payload.target_rules = payload.target_rules.map((r) => ({
            rule_type: r.rule_type, operator: r.operator, hard_exclusion: r.hard_exclusion,
            rule_value_json: (r.valuesCsv || '').split(',').map((v) => v.trim()).filter(Boolean),
        }));
        payload.offers = payload.offers.filter((o) => o.offer_type !== 'DISCOUNT' || o.discount_code);
        if (!payload.ends_at) delete payload.ends_at;
        if (!payload.max_participants) delete payload.max_participants;
        await window.axios.post('/api/campaigns', payload, { headers: { 'Idempotency-Key': `camp-${payload.code}` } });
        showDesigner.value = false; notice.value = `Campaign ${payload.code} drafted`;
        await loadCampaigns();
    } catch (e) { flash(e); }
}
async function campaignAction(c, action) {
    try { await window.axios.post(`/api/campaigns/${c.campaign_id}/${action}`); await loadCampaigns(); } catch (e) { flash(e); }
}
async function testEligibility(c) {
    try {
        const ctx = Object.fromEntries(Object.entries(eligibility.value.context).filter(([, v]) => v !== ''));
        const { data } = await window.axios.post(`/api/campaigns/${c.campaign_id}/check-eligibility`, ctx);
        eligibility.value.result = data;
    } catch (e) { flash(e); }
}

// --- bundles ---
const bundles = ref([]);
const showBundleDesigner = ref(false);
const bundleDraft = ref(null);
const checks = ref({ open: null, items: [] });

const blankBundle = () => ({
    bundle_code: '', display_name: '', bundle_type: 'ACQUISITION',
    componentsCsv: '', channel_code: 'SALES_APP', franchise_id: '',
});

async function loadBundles() {
    const { data } = await window.axios.get('/api/commercial-bundles', { params: { size: 50 } });
    bundles.value = data.items ?? [];
}
async function saveBundle() {
    try {
        const d = bundleDraft.value;
        await window.axios.post('/api/commercial-bundles', {
            bundle_code: d.bundle_code, display_name: d.display_name, bundle_type: d.bundle_type,
            components: d.componentsCsv.split(',').map((v) => v.trim()).filter(Boolean).map((ref, i) => ({ package_ref: ref, mandatory: i === 0, component_role: i === 0 ? 'PRIMARY' : 'ADDON' })),
            availability: [{ channel_code: d.channel_code, franchise_id: d.franchise_id || null }],
        }, { headers: { 'Idempotency-Key': `bun-${d.bundle_code}` } });
        showBundleDesigner.value = false; notice.value = `Bundle ${d.bundle_code} drafted`;
        await loadBundles();
    } catch (e) { flash(e); }
}
async function bundleAction(b, action) {
    try {
        const { data } = await window.axios.post(`/api/commercial-bundles/${b.bundle_id}/${action}`);
        if (action === 'validate') { checks.value = { open: b.bundle_id, items: data.checks ?? [] }; }
        await loadBundles();
    } catch (e) { flash(e); }
}
const nextActions = (status) => ({
    DRAFT: ['validate', 'submit-review'],
    READY_FOR_REVIEW: ['approve'],
    APPROVED: ['activate'],
    ACTIVE: ['retire'],
    SUSPENDED: ['retire'],
}[status] ?? []);

onMounted(() => { loadCampaigns(); loadBundles(); });
</script>

<template>
    <Head title="Commercial Studio" />
    <AuthenticatedLayout>
        <template #header><h2 class="font-semibold text-xl text-gray-800">Commercial Studio — campaigns & bundles</h2></template>

        <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4 flex gap-2">
                <button @click="tab = 'campaigns'" :class="tab === 'campaigns' ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-1 rounded border text-sm">Campaigns (SIP-05)</button>
                <button @click="tab = 'bundles'" :class="tab === 'bundles' ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-1 rounded border text-sm">Bundles (SIP-04)</button>
            </div>
            <div v-if="error" class="mb-3 p-2 bg-red-100 text-red-700 rounded text-sm">{{ error }}</div>
            <div v-if="notice" class="mb-3 p-2 bg-green-100 text-green-700 rounded text-sm">{{ notice }}</div>

            <!-- ============ CAMPAIGNS ============ -->
            <div v-if="tab === 'campaigns'">
                <button @click="design" class="mb-3 px-3 py-1 bg-indigo-600 text-white rounded text-sm">+ Design campaign</button>

                <!-- designer -->
                <div v-if="showDesigner" class="bg-white rounded shadow p-4 mb-4 space-y-3">
                    <div class="grid grid-cols-4 gap-2">
                        <input v-model="draft.code" placeholder="CAMPAIGN_CODE" class="border rounded px-2 py-1 text-sm" />
                        <input v-model="draft.name" placeholder="Display name" class="border rounded px-2 py-1 text-sm" />
                        <select v-model="draft.campaign_type" class="border rounded px-2 py-1 text-sm"><option v-for="t in campaignTypes" :key="t">{{ t }}</option></select>
                        <input v-model.number="draft.max_participants" type="number" placeholder="Max participants" class="border rounded px-2 py-1 text-sm" />
                    </div>
                    <div class="grid grid-cols-4 gap-2">
                        <label class="text-xs text-gray-500 self-center">Window</label>
                        <input v-model="draft.starts_at" type="date" class="border rounded px-2 py-1 text-sm" />
                        <input v-model="draft.ends_at" type="date" class="border rounded px-2 py-1 text-sm" />
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-500 mb-1">Offer (PLM-CFG-04 discount → SIP-03 assignment on redemption)</div>
                        <select v-model="draft.offers[0].discount_code" class="border rounded px-2 py-1 text-sm">
                            <option value="">— pick discount —</option>
                            <option v-for="d in discounts" :key="d">{{ d }}</option>
                        </select>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-500 mb-1">Targeting rules <button @click="addRule" class="px-1 bg-gray-200 rounded">+</button></div>
                        <div v-for="(r, i) in draft.target_rules" :key="i" class="flex gap-2 mb-1">
                            <select v-model="r.rule_type" class="border rounded px-2 py-1 text-sm"><option v-for="t in ruleTypes" :key="t">{{ t }}</option></select>
                            <select v-model="r.operator" class="border rounded px-2 py-1 text-sm"><option>IN</option><option>NOT_IN</option><option>EQ</option></select>
                            <input v-model="r.valuesCsv" placeholder="values, comma-separated" class="border rounded px-2 py-1 text-sm flex-1" />
                            <label class="text-xs self-center"><input type="checkbox" v-model="r.hard_exclusion" /> hard</label>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-gray-500 mb-1">Channels</div>
                        <button v-for="c in channels" :key="c" @click="toggleChannel(c)"
                            class="px-2 py-0.5 rounded text-xs border mr-1"
                            :class="draft.channels.includes(c) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-gray-50 text-gray-500'">{{ c }}</button>
                    </div>
                    <div class="flex gap-2">
                        <button @click="saveCampaign" class="px-3 py-1 bg-indigo-600 text-white rounded text-sm">Save draft</button>
                        <button @click="showDesigner = false" class="px-3 py-1 bg-gray-200 rounded text-sm">Cancel</button>
                    </div>
                </div>

                <!-- list + launcher -->
                <div class="bg-white rounded shadow divide-y">
                    <div v-for="c in campaigns" :key="c.campaign_id" class="p-3">
                        <div class="flex justify-between items-center">
                            <div>
                                <span class="font-semibold">{{ c.code }}</span>
                                <span class="ml-2 text-xs px-1.5 py-0.5 rounded"
                                    :class="{ ACTIVE: 'bg-green-100 text-green-700', DRAFT: 'bg-gray-100 text-gray-600', PAUSED: 'bg-yellow-100 text-yellow-700', ENDED: 'bg-red-100 text-red-600' }[c.status] ?? 'bg-gray-100'">{{ c.status }}</span>
                                <span class="ml-2 text-xs text-gray-400">{{ c.campaign_type }} · {{ (c.channels ?? []).map((x) => x.channel_code).join('/') || 'all channels' }}</span>
                            </div>
                            <div class="flex gap-1">
                                <button v-if="['DRAFT', 'PAUSED'].includes(c.status)" @click="campaignAction(c, 'activate')" class="px-2 py-0.5 bg-green-600 text-white rounded text-xs">Launch</button>
                                <button v-if="c.status === 'ACTIVE'" @click="campaignAction(c, 'pause')" class="px-2 py-0.5 bg-yellow-500 text-white rounded text-xs">Pause</button>
                                <button v-if="['ACTIVE', 'PAUSED'].includes(c.status)" @click="campaignAction(c, 'end')" class="px-2 py-0.5 bg-red-500 text-white rounded text-xs">End</button>
                                <button @click="eligibility.open = eligibility.open === c.campaign_id ? null : c.campaign_id; eligibility.result = null"
                                    class="px-2 py-0.5 bg-gray-200 rounded text-xs">Test eligibility</button>
                            </div>
                        </div>
                        <div v-if="eligibility.open === c.campaign_id" class="mt-2 bg-gray-50 rounded p-2 flex gap-2 items-center text-sm">
                            <select v-model="eligibility.context.channelCode" class="border rounded px-1 py-0.5 text-xs"><option v-for="ch in channels" :key="ch">{{ ch }}</option></select>
                            <input v-model="eligibility.context.franchiseId" placeholder="franchiseId" class="border rounded px-1 py-0.5 text-xs" />
                            <input v-model="eligibility.context.regionCode" placeholder="regionCode" class="border rounded px-1 py-0.5 text-xs" />
                            <input v-model="eligibility.context.packageRef" placeholder="packageRef" class="border rounded px-1 py-0.5 text-xs" />
                            <button @click="testEligibility(c)" class="px-2 py-0.5 bg-indigo-600 text-white rounded text-xs">Check</button>
                            <span v-if="eligibility.result" :class="eligibility.result.eligible ? 'text-green-600' : 'text-red-600'" class="text-xs font-semibold">
                                {{ eligibility.result.eligible ? 'ELIGIBLE' : 'NOT ELIGIBLE: ' + eligibility.result.reasons.join(', ') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ BUNDLES ============ -->
            <div v-else>
                <button @click="bundleDraft = blankBundle(); showBundleDesigner = true" class="mb-3 px-3 py-1 bg-indigo-600 text-white rounded text-sm">+ Compose bundle</button>

                <div v-if="showBundleDesigner" class="bg-white rounded shadow p-4 mb-4 space-y-2">
                    <div class="grid grid-cols-3 gap-2">
                        <input v-model="bundleDraft.bundle_code" placeholder="BUNDLE_CODE" class="border rounded px-2 py-1 text-sm" />
                        <input v-model="bundleDraft.display_name" placeholder="Display name" class="border rounded px-2 py-1 text-sm" />
                        <select v-model="bundleDraft.bundle_type" class="border rounded px-2 py-1 text-sm">
                            <option v-for="t in ['ACQUISITION', 'RETENTION', 'MIGRATION', 'BUSINESS', 'STAFF', 'GENERAL']" :key="t">{{ t }}</option>
                        </select>
                    </div>
                    <input v-model="bundleDraft.componentsCsv" placeholder="package refs, comma-separated (first = PRIMARY mandatory)" class="border rounded px-2 py-1 text-sm w-full" />
                    <div class="grid grid-cols-3 gap-2">
                        <select v-model="bundleDraft.channel_code" class="border rounded px-2 py-1 text-sm"><option v-for="ch in channels" :key="ch">{{ ch }}</option></select>
                        <input v-model="bundleDraft.franchise_id" placeholder="franchiseId (blank = all)" class="border rounded px-2 py-1 text-sm" />
                    </div>
                    <div class="flex gap-2">
                        <button @click="saveBundle" class="px-3 py-1 bg-indigo-600 text-white rounded text-sm">Save draft</button>
                        <button @click="showBundleDesigner = false" class="px-3 py-1 bg-gray-200 rounded text-sm">Cancel</button>
                    </div>
                </div>

                <div class="bg-white rounded shadow divide-y">
                    <div v-for="b in bundles" :key="b.bundle_id" class="p-3">
                        <div class="flex justify-between items-center">
                            <div>
                                <span class="font-semibold">{{ b.bundle_code }}</span>
                                <span class="ml-2 text-xs px-1.5 py-0.5 rounded"
                                    :class="{ ACTIVE: 'bg-green-100 text-green-700', DRAFT: 'bg-gray-100 text-gray-600', READY_FOR_REVIEW: 'bg-blue-100 text-blue-700', APPROVED: 'bg-indigo-100 text-indigo-700', RETIRED: 'bg-red-100 text-red-600' }[b.status] ?? 'bg-gray-100'">{{ b.status }}</span>
                                <span class="ml-2 text-xs text-gray-400">{{ (b.components ?? []).map((x) => x.package_ref).join(' + ') }}</span>
                            </div>
                            <div class="flex gap-1">
                                <button v-for="a in nextActions(b.status)" :key="a" @click="bundleAction(b, a)"
                                    class="px-2 py-0.5 rounded text-xs"
                                    :class="a === 'retire' ? 'bg-red-500 text-white' : a === 'activate' ? 'bg-green-600 text-white' : 'bg-indigo-600 text-white'">{{ a }}</button>
                            </div>
                        </div>
                        <div v-if="checks.open === b.bundle_id && checks.items.length" class="mt-2 bg-gray-50 rounded p-2 text-xs space-y-0.5">
                            <div v-for="ch in checks.items" :key="ch.check_id">
                                <span :class="ch.check_status === 'PASS' ? 'text-green-600' : 'text-red-600'" class="font-semibold">{{ ch.check_status }}</span>
                                <span class="text-gray-500 ml-1">{{ ch.check_code }}</span> — {{ ch.message }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
