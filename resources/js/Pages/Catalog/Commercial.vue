<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import Drawer from '@/Components/Bss/Drawer.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();

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
const flash = (e) => { error.value = e.response?.data?.message ?? t('Request failed'); setTimeout(() => (error.value = null), 6000); };

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
        showDesigner.value = false; notice.value = t('Campaign :code drafted', { code: payload.code });
        setTimeout(() => (notice.value = null), 4000);
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
        showBundleDesigner.value = false; notice.value = t('Bundle :code drafted', { code: d.bundle_code });
        setTimeout(() => (notice.value = null), 4000);
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
const actionTone = (a) => (a === 'retire' ? 'bg-red-500 text-white' : a === 'activate' || a === 'approve' ? 'bg-emerald-600 text-white' : 'bg-op text-white');

onMounted(() => { loadCampaigns(); loadBundles(); });
</script>

<template>
    <Head :title="t('Commercial Studio')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader :title="t('Commercial')" :crumbs="[{ label: 'Catalog' }, { label: 'Commercial' }]">
                <template #actions>
                    <button v-if="tab === 'campaigns'" @click="design" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">+ {{ t('Design campaign') }}</button>
                    <button v-else @click="bundleDraft = blankBundle(); showBundleDesigner = true" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">+ {{ t('Compose bundle') }}</button>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <div class="inline-flex gap-1 rounded-xl bg-gray-100 p-1">
                <button @click="tab = 'campaigns'" class="rounded-lg px-3 py-1.5 text-sm font-medium transition" :class="tab === 'campaigns' ? 'bg-white text-op shadow-sm' : 'text-gray-500 hover:text-gray-700'">{{ t('Campaigns (SIP-05)') }}</button>
                <button @click="tab = 'bundles'" class="rounded-lg px-3 py-1.5 text-sm font-medium transition" :class="tab === 'bundles' ? 'bg-white text-op shadow-sm' : 'text-gray-500 hover:text-gray-700'">{{ t('Bundles (SIP-04)') }}</button>
            </div>
            <div v-if="error" class="rounded-lg bg-red-50 p-2 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</div>
            <div v-if="notice" class="rounded-lg bg-emerald-50 p-2 text-sm text-emerald-700 ring-1 ring-emerald-100">{{ notice }}</div>

            <!-- ============ CAMPAIGNS ============ -->
            <Panel v-if="tab === 'campaigns'" :title="t('Campaigns')">
                <div class="divide-y divide-gray-50">
                    <div v-for="c in campaigns" :key="c.campaign_id" class="py-3 first:pt-0">
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <span class="font-semibold text-gray-800">{{ c.code }}</span>
                                <StatusBadge :status="c.status" class="ml-2" />
                                <span class="ml-2 text-xs text-gray-400">{{ c.campaign_type }} · {{ (c.channels ?? []).map((x) => x.channel_code).join('/') || t('all channels') }}</span>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <button v-if="['DRAFT', 'PAUSED'].includes(c.status)" @click="campaignAction(c, 'activate')" class="rounded bg-emerald-600 px-2 py-0.5 text-xs text-white">{{ t('Launch') }}</button>
                                <button v-if="c.status === 'ACTIVE'" @click="campaignAction(c, 'pause')" class="rounded bg-amber-500 px-2 py-0.5 text-xs text-white">{{ t('Pause') }}</button>
                                <button v-if="['ACTIVE', 'PAUSED'].includes(c.status)" @click="campaignAction(c, 'end')" class="rounded bg-red-500 px-2 py-0.5 text-xs text-white">{{ t('End') }}</button>
                                <button @click="eligibility.open = eligibility.open === c.campaign_id ? null : c.campaign_id; eligibility.result = null"
                                    class="rounded border px-2 py-0.5 text-xs hover:bg-gray-50">{{ t('Test eligibility') }}</button>
                            </div>
                        </div>
                        <div v-if="eligibility.open === c.campaign_id" class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 p-2 text-sm">
                            <select v-model="eligibility.context.channelCode" class="rounded border-gray-300 px-1 py-0.5 text-xs"><option v-for="ch in channels" :key="ch">{{ ch }}</option></select>
                            <input v-model="eligibility.context.franchiseId" placeholder="franchiseId" class="rounded border-gray-300 px-1 py-0.5 text-xs" />
                            <input v-model="eligibility.context.regionCode" placeholder="regionCode" class="rounded border-gray-300 px-1 py-0.5 text-xs" />
                            <input v-model="eligibility.context.packageRef" placeholder="packageRef" class="rounded border-gray-300 px-1 py-0.5 text-xs" />
                            <button @click="testEligibility(c)" class="rounded bg-op px-2 py-0.5 text-xs text-white">{{ t('Check') }}</button>
                            <span v-if="eligibility.result" :class="eligibility.result.eligible ? 'text-emerald-600' : 'text-red-600'" class="text-xs font-semibold">
                                {{ eligibility.result.eligible ? t('ELIGIBLE') : t('NOT ELIGIBLE: ') + eligibility.result.reasons.join(', ') }}
                            </span>
                        </div>
                    </div>
                    <p v-if="!campaigns.length" class="py-4 text-sm text-gray-400">{{ t('No campaigns yet.') }}</p>
                </div>
            </Panel>

            <!-- ============ BUNDLES ============ -->
            <Panel v-else :title="t('Bundles')">
                <div class="divide-y divide-gray-50">
                    <div v-for="b in bundles" :key="b.bundle_id" class="py-3 first:pt-0">
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <span class="font-semibold text-gray-800">{{ b.bundle_code }}</span>
                                <StatusBadge :status="b.status" class="ml-2" />
                                <span class="ml-2 text-xs text-gray-400">{{ (b.components ?? []).map((x) => x.package_ref).join(' + ') }}</span>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <button v-for="a in nextActions(b.status)" :key="a" @click="bundleAction(b, a)" class="rounded px-2 py-0.5 text-xs" :class="actionTone(a)">{{ t(a) }}</button>
                            </div>
                        </div>
                        <div v-if="checks.open === b.bundle_id && checks.items.length" class="mt-2 space-y-0.5 rounded-lg bg-gray-50 p-2 text-xs">
                            <div v-for="ch in checks.items" :key="ch.check_id">
                                <span :class="ch.check_status === 'PASS' ? 'text-emerald-600' : 'text-red-600'" class="font-semibold">{{ ch.check_status }}</span>
                                <span class="ml-1 text-gray-500">{{ ch.check_code }}</span> — {{ ch.message }}
                            </div>
                        </div>
                    </div>
                    <p v-if="!bundles.length" class="py-4 text-sm text-gray-400">{{ t('No bundles yet.') }}</p>
                </div>
            </Panel>
        </div>

        <!-- Campaign designer drawer -->
        <Drawer :open="showDesigner" :title="t('Design campaign')" @update:open="showDesigner = $event">
            <div v-if="draft" class="space-y-4">
                <div class="grid grid-cols-2 gap-2">
                    <input v-model="draft.code" placeholder="CAMPAIGN_CODE" class="rounded-md border-gray-300 text-sm" />
                    <input v-model="draft.name" :placeholder="t('Display name')" class="rounded-md border-gray-300 text-sm" />
                    <select v-model="draft.campaign_type" class="rounded-md border-gray-300 text-sm"><option v-for="ct in campaignTypes" :key="ct">{{ ct }}</option></select>
                    <input v-model.number="draft.max_participants" type="number" :placeholder="t('Max participants')" class="rounded-md border-gray-300 text-sm" />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="mb-1 block text-xs text-gray-500">{{ t('Starts') }}</label><input v-model="draft.starts_at" type="date" class="w-full rounded-md border-gray-300 text-sm" /></div>
                    <div><label class="mb-1 block text-xs text-gray-500">{{ t('Ends') }}</label><input v-model="draft.ends_at" type="date" class="w-full rounded-md border-gray-300 text-sm" /></div>
                </div>
                <div>
                    <div class="mb-1 text-xs font-semibold text-gray-500">{{ t('Offer (PLM-CFG-04 discount → SIP-03 assignment on redemption)') }}</div>
                    <select v-model="draft.offers[0].discount_code" class="w-full rounded-md border-gray-300 text-sm">
                        <option value="">{{ t('— pick discount —') }}</option>
                        <option v-for="d in discounts" :key="d">{{ d }}</option>
                    </select>
                </div>
                <div>
                    <div class="mb-1 text-xs font-semibold text-gray-500">{{ t('Targeting rules') }} <button @click="addRule" class="rounded bg-gray-200 px-1.5">+</button></div>
                    <div v-for="(r, i) in draft.target_rules" :key="i" class="mb-1 flex gap-2">
                        <select v-model="r.rule_type" class="rounded-md border-gray-300 text-sm"><option v-for="rt in ruleTypes" :key="rt">{{ rt }}</option></select>
                        <select v-model="r.operator" class="rounded-md border-gray-300 text-sm"><option>IN</option><option>NOT_IN</option><option>EQ</option></select>
                        <input v-model="r.valuesCsv" :placeholder="t('values, comma-separated')" class="flex-1 rounded-md border-gray-300 text-sm" />
                        <label class="self-center text-xs"><input type="checkbox" v-model="r.hard_exclusion" /> {{ t('hard') }}</label>
                    </div>
                </div>
                <div>
                    <div class="mb-1 text-xs font-semibold text-gray-500">{{ t('Channels') }}</div>
                    <button v-for="c in channels" :key="c" @click="toggleChannel(c)"
                        class="mb-1 mr-1 rounded border px-2 py-0.5 text-xs"
                        :class="draft.channels.includes(c) ? 'border-op bg-op text-white' : 'bg-gray-50 text-gray-500'">{{ c }}</button>
                </div>
                <div class="flex gap-2 pt-2">
                    <button @click="saveCampaign" class="flex-1 rounded-md bg-op px-3 py-2 text-sm font-semibold text-white hover:opacity-90">{{ t('Save draft') }}</button>
                    <button @click="showDesigner = false" class="rounded-md bg-gray-100 px-3 py-2 text-sm">{{ t('Cancel') }}</button>
                </div>
            </div>
        </Drawer>

        <!-- Bundle designer drawer -->
        <Drawer :open="showBundleDesigner" :title="t('Compose bundle')" width="max-w-lg" @update:open="showBundleDesigner = $event">
            <div v-if="bundleDraft" class="space-y-3">
                <div class="grid grid-cols-1 gap-2">
                    <input v-model="bundleDraft.bundle_code" placeholder="BUNDLE_CODE" class="rounded-md border-gray-300 text-sm" />
                    <input v-model="bundleDraft.display_name" :placeholder="t('Display name')" class="rounded-md border-gray-300 text-sm" />
                    <select v-model="bundleDraft.bundle_type" class="rounded-md border-gray-300 text-sm">
                        <option v-for="bt in ['ACQUISITION', 'RETENTION', 'MIGRATION', 'BUSINESS', 'STAFF', 'GENERAL']" :key="bt">{{ bt }}</option>
                    </select>
                </div>
                <input v-model="bundleDraft.componentsCsv" :placeholder="t('package refs, comma-separated (first = PRIMARY mandatory)')" class="w-full rounded-md border-gray-300 text-sm" />
                <div class="grid grid-cols-2 gap-2">
                    <select v-model="bundleDraft.channel_code" class="rounded-md border-gray-300 text-sm"><option v-for="ch in channels" :key="ch">{{ ch }}</option></select>
                    <input v-model="bundleDraft.franchise_id" :placeholder="t('franchiseId (blank = all)')" class="rounded-md border-gray-300 text-sm" />
                </div>
                <div class="flex gap-2 pt-2">
                    <button @click="saveBundle" class="flex-1 rounded-md bg-op px-3 py-2 text-sm font-semibold text-white hover:opacity-90">{{ t('Save draft') }}</button>
                    <button @click="showBundleDesigner = false" class="rounded-md bg-gray-100 px-3 py-2 text-sm">{{ t('Cancel') }}</button>
                </div>
            </div>
        </Drawer>
    </AuthenticatedLayout>
</template>
