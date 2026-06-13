<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

// Brand & Locale — the operator's theme (FE-APP theming: --op-primary, logo, regional
// formatting) AND the i18n resource catalog. Theme edits PATCH /operator-config and preview
// live (the chosen colour is applied to --op-primary instantly); translations are managed by
// culture/domain/section, globally or as an operator override. en is the source culture.
const { t } = useI18n();
const op = computed(() => usePage().props.operatorConfig ?? null);
const tab = ref('brand'); // brand | localization

// ----------------------------------------------------------------------------- BRAND / THEME
const cfg = ref({ display_name: '', theme_primary_color: '#4f46e5', theme_logo_url: '', default_locale: 'en', currency_code: 'KES', timezone: 'Africa/Nairobi', date_format: 'd/m/Y', log_level: 'info' });
const savedColor = ref('#4f46e5');
const brandMsg = ref(null);
const brandErr = ref(null);
const ROOT = () => document.documentElement;

async function loadConfig() {
    try {
        const { data } = await window.axios.get('/api/operator-config');
        cfg.value = { ...cfg.value, ...data };
        savedColor.value = data.theme_primary_color ?? savedColor.value;
    } catch (e) { /* defaults are fine */ }
}
// Live preview: paint --op-primary on the document root so every .bg-op/.text-op across the
// whole shell recolours instantly as the admin drags the picker.
function previewColor() { ROOT().style.setProperty('--op-primary', cfg.value.theme_primary_color); }
function resetPreview() { cfg.value.theme_primary_color = savedColor.value; ROOT().style.setProperty('--op-primary', savedColor.value); }
async function saveBrand() {
    brandMsg.value = brandErr.value = null;
    try {
        const { data } = await window.axios.patch('/api/operator-config', {
            display_name: cfg.value.display_name,
            theme_primary_color: cfg.value.theme_primary_color,
            theme_logo_url: cfg.value.theme_logo_url || null,
            default_locale: cfg.value.default_locale,
            currency_code: cfg.value.currency_code,
            timezone: cfg.value.timezone,
            date_format: cfg.value.date_format,
            log_level: cfg.value.log_level,
        });
        savedColor.value = data.theme_primary_color ?? cfg.value.theme_primary_color;
        brandMsg.value = t('Brand saved — applies on next page load for everyone.');
        setTimeout(() => (brandMsg.value = null), 4000);
    } catch (e) { brandErr.value = e.response?.data?.message ?? t('Save failed'); }
}

// ------------------------------------------------------------------------------ LOCALIZATION
const locales = ref([]);
const domains = ref([]);
const sections = ref([]);
const rows = ref([]);          // rows of the selected culture (catalog rows, editable)
const allRows = ref([]);       // all cultures (to compute missing keys)
const locale = ref('sw');
const newLocale = ref('');
const scope = ref('*');        // '*' global or the operator's code
const domainFilter = ref('');
const sectionFilter = ref('');
const search = ref('');
const dirty = ref({});         // id -> edited value
const showMissing = ref(false);
const message = ref(null);
const error = ref(null);

const blank = () => ({ domain: 'COMMON', section: 'general', key: '', value: '' });
const draft = ref(blank());

async function loadMeta() {
    const m = await window.axios.get('/api/i18n/meta');
    locales.value = m.data.locales ?? [];
    domains.value = m.data.domains ?? [];
    sections.value = m.data.sections ?? [];
}
async function load() {
    error.value = null;
    try {
        const params = { locale: locale.value };
        if (domainFilter.value) params.domain = domainFilter.value;
        if (sectionFilter.value) params.section = sectionFilter.value;
        if (search.value) params.q = search.value;
        const r = await window.axios.get('/api/i18n/translations', { params });
        rows.value = r.data.items ?? [];
        const all = await window.axios.get('/api/i18n/translations');
        allRows.value = all.data.items ?? [];
        dirty.value = {};
    } catch (e) { error.value = e.response?.data?.message ?? t('Failed to load'); }
}

// Keys translated in OTHER cultures but absent from this one → translation debt.
const missingKeys = computed(() => {
    const here = new Set(rows.value.map((r) => `${r.domain}|${r.section}|${r.key}`));
    const seen = new Map();
    for (const r of allRows.value) {
        if (r.locale === locale.value) continue;
        const id = `${r.domain}|${r.section}|${r.key}`;
        if (!here.has(id) && !seen.has(id)) seen.set(id, r);
    }
    return [...seen.values()];
});

async function saveDirty() {
    const items = rows.value
        .filter((r) => dirty.value[r.id] !== undefined && dirty.value[r.id] !== r.value)
        .map((r) => ({
            locale: r.locale, domain: r.domain, section: r.section, key: r.key,
            value: dirty.value[r.id], operator_code: r.operator_code,
        }));
    if (!items.length) return;
    await window.axios.post('/api/i18n/translations', { items });
    message.value = t(':n translation(s) saved', { n: items.length });
    setTimeout(() => (message.value = null), 4000);
    await load();
}
async function addRow(prefill = null) {
    const d = prefill ?? draft.value;
    if (!d.key || !d.value) return;
    await window.axios.post('/api/i18n/translations', { items: [{
        locale: locale.value, domain: d.domain, section: d.section,
        key: d.key, value: d.value, operator_code: scope.value,
    }] });
    draft.value = blank();
    message.value = t('Saved');
    setTimeout(() => (message.value = null), 4000);
    await Promise.all([loadMeta(), load()]);
}
async function remove(row) {
    await window.axios.delete(`/api/i18n/translations/${row.id}`);
    await load();
}
function switchLocale(l) { locale.value = l; load(); }
function addCulture() {
    const code = newLocale.value.trim();
    if (!code) return;
    if (!locales.value.includes(code)) locales.value.push(code);
    newLocale.value = '';
    switchLocale(code);
}

onMounted(async () => { await loadConfig(); await loadMeta(); await load(); });
</script>

<template>
    <Head title="Brand & Locale" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader :title="t('Brand & Locale')" :crumbs="[{ label: 'Brand & Locale' }, { label: tab === 'brand' ? 'Theme' : 'Localization' }]" />
        </template>

        <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <div class="inline-flex gap-1 rounded-xl bg-gray-100 p-1">
                <button @click="tab = 'brand'" class="rounded-lg px-3 py-1.5 text-sm font-medium transition" :class="tab === 'brand' ? 'bg-white text-op shadow-sm' : 'text-gray-500 hover:text-gray-700'">{{ t('Brand & theme') }}</button>
                <button @click="tab = 'localization'" class="rounded-lg px-3 py-1.5 text-sm font-medium transition" :class="tab === 'localization' ? 'bg-white text-op shadow-sm' : 'text-gray-500 hover:text-gray-700'">{{ t('Localization') }}</button>
            </div>

            <!-- ============================ BRAND / THEME ============================ -->
            <div v-if="tab === 'brand'" class="grid grid-cols-12 gap-5">
                <div class="col-span-12 lg:col-span-6">
                    <Panel :title="t('Operator brand')" :subtitle="t('Theme & regional formatting for this operator')">
                        <p v-if="brandMsg" class="mb-3 rounded-lg bg-emerald-50 p-2 text-sm text-emerald-700 ring-1 ring-emerald-100">{{ brandMsg }}</p>
                        <p v-if="brandErr" class="mb-3 rounded-lg bg-red-50 p-2 text-sm text-red-700 ring-1 ring-red-100">{{ brandErr }}</p>
                        <div class="space-y-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Display name') }}</label>
                                <input v-model="cfg.display_name" class="w-full rounded-md border-gray-300 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Primary colour') }}</label>
                                <div class="flex items-center gap-2">
                                    <input type="color" v-model="cfg.theme_primary_color" @input="previewColor" class="h-9 w-12 cursor-pointer rounded border border-gray-300" />
                                    <input v-model="cfg.theme_primary_color" @input="previewColor" class="w-32 rounded-md border-gray-300 font-mono text-sm" />
                                    <button @click="resetPreview" class="rounded-md border px-2 py-1.5 text-xs hover:bg-gray-50">{{ t('Reset preview') }}</button>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Logo URL') }}</label>
                                <input v-model="cfg.theme_logo_url" placeholder="https://…" class="w-full rounded-md border-gray-300 text-sm" />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Default locale') }}</label>
                                    <select v-model="cfg.default_locale" class="w-full rounded-md border-gray-300 text-sm">
                                        <option value="en">en</option><option value="sw">sw</option><option value="fr">fr</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Currency') }}</label>
                                    <input v-model="cfg.currency_code" maxlength="3" class="w-full rounded-md border-gray-300 text-sm uppercase" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Timezone') }}</label>
                                    <input v-model="cfg.timezone" class="w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Date format') }}</label>
                                    <input v-model="cfg.date_format" class="w-full rounded-md border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('Log level') }}</label>
                                    <select v-model="cfg.log_level" class="w-full rounded-md border-gray-300 text-sm">
                                        <option>debug</option><option>info</option><option>warning</option><option>error</option>
                                    </select>
                                </div>
                            </div>
                            <button @click="saveBrand" class="w-full rounded-md bg-op px-4 py-2 text-sm font-semibold text-white hover:opacity-90">{{ t('Save brand') }}</button>
                        </div>
                    </Panel>
                </div>

                <!-- LIVE PREVIEW -->
                <div class="col-span-12 lg:col-span-6">
                    <Panel :title="t('Live preview')" :subtitle="t('Components recolour as you pick')">
                        <div class="space-y-4">
                            <div class="flex items-center gap-3 rounded-lg bg-op-soft p-3">
                                <img v-if="cfg.theme_logo_url" :src="cfg.theme_logo_url" alt="logo" class="h-8 w-8 rounded object-contain" />
                                <div v-else class="flex h-8 w-8 items-center justify-center rounded bg-op text-sm font-bold text-white">{{ (cfg.display_name || op?.operator_code || 'OP').slice(0, 2).toUpperCase() }}</div>
                                <span class="font-semibold text-op">{{ cfg.display_name || op?.operator_code || t('Operator') }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <StatCard :label="t('Active subscriptions')" :value="1280" tone="indigo" sub="▲ 4% vs prev" />
                                <StatCard :label="t('Collected')" :value="'KES 2.4M'" tone="emerald" />
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white">{{ t('Primary action') }}</button>
                                <span class="rounded-md bg-op-soft px-3 py-1.5 text-sm font-medium text-op">{{ t('Soft') }}</span>
                                <StatusBadge status="ACTIVE" />
                                <StatusBadge status="PENDING" />
                                <StatusBadge status="OVERDUE" />
                            </div>
                            <div class="inline-flex gap-1 rounded-xl bg-gray-100 p-1">
                                <span class="rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-op shadow-sm">{{ t('Selected tab') }}</span>
                                <span class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-500">{{ t('Other tab') }}</span>
                            </div>
                        </div>
                    </Panel>
                </div>
            </div>

            <!-- ============================ LOCALIZATION ============================ -->
            <div v-else class="space-y-4">
                <p v-if="message" class="rounded-lg bg-emerald-50 p-2 text-sm text-emerald-700 ring-1 ring-emerald-100">{{ message }}</p>
                <p v-if="error" class="rounded-lg bg-red-50 p-2 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</p>

                <Panel :title="t('Localization')" :subtitle="t('en (Kenyan English) is the source culture — keys are the text')">
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">{{ t('Culture') }}</label>
                            <div class="flex gap-1">
                                <button v-for="l in locales.filter((x) => x !== 'en')" :key="l" @click="switchLocale(l)"
                                    class="rounded-md border px-2 py-1 text-sm" :class="locale === l ? 'border-op bg-op text-white' : 'bg-white text-gray-600'">{{ l }}</button>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">{{ t('Add culture (e.g. fr-SN, en-UG)') }}</label>
                            <div class="flex gap-1">
                                <input v-model="newLocale" placeholder="code" class="w-24 rounded-md border-gray-300 text-sm" @keyup.enter="addCulture" />
                                <button @click="addCulture" class="rounded-md bg-gray-700 px-2 text-sm text-white">+</button>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">{{ t('Scope for new entries') }}</label>
                            <select v-model="scope" class="rounded-md border-gray-300 text-sm">
                                <option value="*">{{ t('Global (all operators)') }}</option>
                                <option v-if="op" :value="op.operator_code">{{ op.operator_code }} {{ t('override') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">{{ t('Domain') }}</label>
                            <select v-model="domainFilter" @change="load" class="rounded-md border-gray-300 text-sm">
                                <option value="">{{ t('All domains') }}</option>
                                <option v-for="d in domains" :key="d" :value="d">{{ d }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">{{ t('Section') }}</label>
                            <select v-model="sectionFilter" @change="load" class="rounded-md border-gray-300 text-sm">
                                <option value="">{{ t('All sections') }}</option>
                                <option v-for="s in sections" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div class="min-w-40 flex-1">
                            <label class="mb-1 block text-xs text-gray-500">{{ t('Search') }}</label>
                            <input v-model="search" @keyup.enter="load" placeholder="key or value…" class="w-full rounded-md border-gray-300 text-sm" />
                        </div>
                        <button @click="load" class="rounded-md bg-gray-700 px-3 py-1.5 text-sm text-white">{{ t('Search') }}</button>
                        <button @click="saveDirty" class="rounded-md bg-op px-3 py-1.5 text-sm text-white">{{ t('Save') }} ✓</button>
                    </div>
                </Panel>

                <Panel :title="t('Resources')">
                    <DataTable :columns="[{ key: 'domain', label: 'Domain' }, { key: 'section', label: 'Section' }, { key: 'key', label: 'Key (source text)' }, { key: 'value', label: locale + ' value' }, { key: 'operator_code', label: 'Scope' }]"
                        :rows="rows" row-key="id" empty="No resources for this culture yet — add below or fill the missing list.">
                        <template #cell-domain="{ value }"><span class="text-xs">{{ value }}</span></template>
                        <template #cell-section="{ value }"><span class="text-xs text-gray-500">{{ value }}</span></template>
                        <template #cell-key="{ value }"><span class="font-mono text-xs">{{ value }}</span></template>
                        <template #cell-value="{ row }">
                            <input :value="dirty[row.id] ?? row.value" @input="dirty[row.id] = $event.target.value"
                                class="w-full rounded-md border-gray-300 px-2 py-0.5 text-sm"
                                :class="dirty[row.id] !== undefined && dirty[row.id] !== row.value ? 'border-amber-400 bg-amber-50' : ''" />
                        </template>
                        <template #cell-operator_code="{ value }">
                            <span class="rounded px-1.5 py-0.5 text-xs" :class="value === '*' ? 'bg-gray-100 text-gray-600' : 'bg-op-soft font-semibold text-op'">{{ value === '*' ? t('global') : value }}</span>
                        </template>
                        <template #row-actions="{ row }">
                            <button @click="remove(row)" class="text-xs text-red-500 hover:underline">{{ t('Delete') }}</button>
                        </template>
                    </DataTable>
                </Panel>

                <div class="grid grid-cols-12 gap-5">
                    <div class="col-span-12 lg:col-span-5">
                        <Panel :title="t('Add resource (:l, :s)', { l: locale, s: scope === '*' ? t('global') : scope })">
                            <div class="space-y-2">
                                <div class="flex gap-2">
                                    <input v-model="draft.domain" placeholder="domain (NAV, BILLING…)" class="flex-1 rounded-md border-gray-300 text-sm" />
                                    <input v-model="draft.section" placeholder="section (menu, errors…)" class="flex-1 rounded-md border-gray-300 text-sm" />
                                </div>
                                <input v-model="draft.key" placeholder="key — the en source text" class="w-full rounded-md border-gray-300 text-sm" />
                                <input v-model="draft.value" :placeholder="`${locale} translation`" class="w-full rounded-md border-gray-300 text-sm" @keyup.enter="addRow()" />
                                <button @click="addRow()" class="rounded-md bg-op px-3 py-1.5 text-sm text-white hover:opacity-90">{{ t('Create') }}</button>
                            </div>
                        </Panel>
                    </div>
                    <div class="col-span-12 lg:col-span-7">
                        <Panel>
                            <button @click="showMissing = !showMissing" class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {{ t('Missing in :l (:n)', { l: locale, n: missingKeys.length }) }} {{ showMissing ? '▾' : '▸' }}
                            </button>
                            <div v-if="showMissing" class="max-h-64 overflow-y-auto">
                                <div v-for="m in missingKeys" :key="`${m.domain}|${m.section}|${m.key}`" class="flex items-center gap-2 border-t border-gray-50 py-1 text-sm">
                                    <span class="text-xs text-gray-400">{{ m.domain }}/{{ m.section }}</span>
                                    <span class="flex-1 truncate font-mono text-xs">{{ m.key }}</span>
                                    <span class="max-w-40 truncate text-xs text-gray-400">{{ m.locale }}: {{ m.value }}</span>
                                    <button @click="draft = { domain: m.domain, section: m.section, key: m.key, value: '' }" class="text-xs text-op hover:underline">{{ t('translate') }}</button>
                                </div>
                                <div v-if="!missingKeys.length" class="text-sm text-gray-400">{{ t('Nothing missing — this culture covers every key.') }}</div>
                            </div>
                        </Panel>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
