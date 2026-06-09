<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

// Localization Studio: the i18n resource catalog, managed by culture (locale),
// domain and section — globally or as an override for the current operator.
// The base culture (en) needs no rows: keys ARE the source text.
const { t } = useI18n();
const op = computed(() => usePage().props.operatorConfig ?? null);

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
    } catch (e) { error.value = e.response?.data?.message ?? 'Failed to load'; }
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
    message.value = `${items.length} ${t('translation(s) saved')}`;
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

onMounted(async () => { await loadMeta(); await load(); });
</script>

<template>
    <Head title="Localization Studio" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">{{ t('Localization') }} Studio</h2>
                <div class="text-xs text-gray-500">en (Kenyan English) is the source culture — keys are the text</div>
            </div>
        </template>

        <div class="py-6 mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-4">
            <p v-if="message" class="p-2 bg-green-50 text-green-700 rounded text-sm">{{ message }}</p>
            <p v-if="error" class="p-2 bg-red-100 text-red-700 rounded text-sm">{{ error }}</p>

            <!-- Culture + scope + filters -->
            <div class="bg-white rounded shadow p-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Culture</label>
                    <div class="flex gap-1">
                        <button v-for="l in locales.filter((x) => x !== 'en')" :key="l" @click="switchLocale(l)"
                            class="px-2 py-1 rounded text-sm border"
                            :class="locale === l ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-600'">{{ l }}</button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Add culture (e.g. fr-SN, en-UG)</label>
                    <div class="flex gap-1">
                        <input v-model="newLocale" placeholder="code" class="border rounded px-2 py-1 text-sm w-24" @keyup.enter="addCulture" />
                        <button @click="addCulture" class="px-2 py-1 bg-gray-700 text-white rounded text-sm">+</button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Scope for new entries</label>
                    <select v-model="scope" class="border rounded px-2 py-1 text-sm">
                        <option value="*">Global (all operators)</option>
                        <option v-if="op" :value="op.operator_code">{{ op.operator_code }} override</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Domain</label>
                    <select v-model="domainFilter" @change="load" class="border rounded px-2 py-1 text-sm">
                        <option value="">All domains</option>
                        <option v-for="d in domains" :key="d" :value="d">{{ d }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Section</label>
                    <select v-model="sectionFilter" @change="load" class="border rounded px-2 py-1 text-sm">
                        <option value="">All sections</option>
                        <option v-for="s in sections" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>
                <div class="flex-1 min-w-40">
                    <label class="block text-xs text-gray-500 mb-1">{{ t('Search') }}</label>
                    <input v-model="search" @keyup.enter="load" placeholder="key or value…" class="border rounded px-2 py-1 text-sm w-full" />
                </div>
                <button @click="load" class="px-3 py-1.5 bg-gray-700 text-white rounded text-sm">{{ t('Search') }}</button>
                <button @click="saveDirty" class="px-3 py-1.5 bg-indigo-600 text-white rounded text-sm">{{ t('Save') }} ✓</button>
            </div>

            <!-- Resource table -->
            <div class="bg-white rounded shadow p-4">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase">
                            <th class="py-1">Domain</th><th>Section</th><th>Key (source text)</th>
                            <th class="w-1/3">{{ locale }} value</th><th>Scope</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id" class="border-t">
                            <td class="py-1 text-xs">{{ r.domain }}</td>
                            <td class="text-xs text-gray-500">{{ r.section }}</td>
                            <td class="font-mono text-xs">{{ r.key }}</td>
                            <td>
                                <input :value="dirty[r.id] ?? r.value" @input="dirty[r.id] = $event.target.value"
                                    class="border rounded px-2 py-0.5 text-sm w-full"
                                    :class="dirty[r.id] !== undefined && dirty[r.id] !== r.value ? 'border-amber-400 bg-amber-50' : ''" />
                            </td>
                            <td>
                                <span class="text-xs px-1.5 py-0.5 rounded"
                                    :class="r.operator_code === '*' ? 'bg-gray-100 text-gray-600' : 'bg-indigo-50 text-indigo-700 font-semibold'">
                                    {{ r.operator_code === '*' ? 'global' : r.operator_code }}
                                </span>
                            </td>
                            <td class="text-right">
                                <button @click="remove(r)" class="text-xs text-red-500 hover:underline">{{ t('Delete') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!rows.length" class="text-sm text-gray-400 py-3">No resources for this culture yet — add below or fill the missing list.</div>
            </div>

            <!-- Add entry + missing keys -->
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-5 bg-white rounded shadow p-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Add resource ({{ locale }}, {{ scope === '*' ? 'global' : scope }})</div>
                    <div class="space-y-2">
                        <div class="flex gap-2">
                            <input v-model="draft.domain" placeholder="domain (NAV, BILLING…)" class="border rounded px-2 py-1 text-sm flex-1" />
                            <input v-model="draft.section" placeholder="section (menu, errors…)" class="border rounded px-2 py-1 text-sm flex-1" />
                        </div>
                        <input v-model="draft.key" placeholder="key — the en source text" class="border rounded px-2 py-1 text-sm w-full" />
                        <input v-model="draft.value" :placeholder="`${locale} translation`" class="border rounded px-2 py-1 text-sm w-full" @keyup.enter="addRow()" />
                        <button @click="addRow()" class="px-3 py-1.5 bg-indigo-600 text-white rounded text-sm">{{ t('Create') }}</button>
                    </div>
                </div>
                <div class="col-span-7 bg-white rounded shadow p-4">
                    <button @click="showMissing = !showMissing" class="text-xs font-semibold text-gray-500 uppercase mb-2">
                        Missing in {{ locale }} ({{ missingKeys.length }}) {{ showMissing ? '▾' : '▸' }}
                    </button>
                    <div v-if="showMissing" class="max-h-64 overflow-y-auto">
                        <div v-for="m in missingKeys" :key="`${m.domain}|${m.section}|${m.key}`"
                            class="flex items-center gap-2 border-t py-1 text-sm">
                            <span class="text-xs text-gray-400">{{ m.domain }}/{{ m.section }}</span>
                            <span class="font-mono text-xs flex-1 truncate">{{ m.key }}</span>
                            <span class="text-xs text-gray-400 truncate max-w-40">{{ m.locale }}: {{ m.value }}</span>
                            <button @click="draft = { domain: m.domain, section: m.section, key: m.key, value: '' }"
                                class="text-xs text-indigo-600 hover:underline">translate</button>
                        </div>
                        <div v-if="!missingKeys.length" class="text-sm text-gray-400">Nothing missing — this culture covers every key.</div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
