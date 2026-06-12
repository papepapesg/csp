<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();

// Template Studio (NOT-01, O-3). Designs the DD-authoritative `template` model: one row
// per (operator, template_format, template_purpose_code, locale, version). A "purpose"
// like INVOICE_CYCLE_POSTPAID decomposes into format rows — PDF, EMAIL_SUBJECT,
// EMAIL_HTML, EMAIL_TEXT, SMS_TEXT — each versioned and authored DRAFT -> ACTIVE.
// {{placeholders}} fill from the event payload at render time.
const formats = ['PDF', 'EMAIL_SUBJECT', 'EMAIL_HTML', 'EMAIL_TEXT', 'SMS_TEXT'];
const tab = ref('notifications'); // notifications | invoices

const templates = ref([]);
const current = ref(null);
const serverPreview = ref(null);
const sampleVars = ref('{\n  "invoiceNumber": "Inv-WIK-2026-000001",\n  "currency": "KES",\n  "amount": "2500.00",\n  "dueDate": "2026-07-01"\n}');
const error = ref(null);
const notice = ref(null);

const invoiceTemplates = ref([]);
const currentInvoice = ref(null);

const engineFor = (f) => (f === 'PDF' ? 'HTML_TO_PDF' : 'HANDLEBARS');
const blank = () => ({ template_purpose_code: '', template_format: 'SMS_TEXT', locale: 'en', engine_type: 'HANDLEBARS', template_payload: '', status: 'DRAFT', version: null, id: null });

// Group by purpose code, then by format — each entry is the version list for that cell.
const grouped = computed(() => {
    const m = {};
    for (const t of templates.value) {
        (m[t.template_purpose_code] ??= {});
        (m[t.template_purpose_code][t.template_format] ??= []).push(t);
    }
    return m;
});
const isPdf = computed(() => current.value?.template_format === 'PDF');
const localPreview = computed(() => {
    if (!current.value || isPdf.value) return null;
    let vars = {}; try { vars = JSON.parse(sampleVars.value || '{}'); } catch { /* ignore */ }
    return (current.value.template_payload || '').replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, k) => (vars[k] ?? `{{${k}}}`));
});

async function load() {
    const { data } = await window.axios.get('/api/admin/templates');
    templates.value = data.items ?? [];
    const inv = await window.axios.get('/api/invoice-templates');
    invoiceTemplates.value = inv.data.items ?? [];
}
function open(t) { current.value = JSON.parse(JSON.stringify(t)); serverPreview.value = null; }
function create() { current.value = blank(); serverPreview.value = null; }
function onFormatChange() { if (current.value) current.value.engine_type = engineFor(current.value.template_format); }

// Save always creates the NEXT DRAFT version (templates are immutable once authored).
async function save() {
    error.value = notice.value = null;
    try {
        let sample = {}; try { sample = JSON.parse(sampleVars.value || '{}'); } catch { /* ignore */ }
        const { data } = await window.axios.post('/api/admin/templates', {
            template_format: current.value.template_format,
            template_purpose_code: current.value.template_purpose_code,
            locale: current.value.locale,
            engine_type: current.value.engine_type,
            template_payload: current.value.template_payload,
            sample_data: sample,
        });
        current.value.id = data.template_id; current.value.version = data.version; current.value.status = data.status;
        notice.value = `Saved draft v${data.version}`; await load();
    } catch (e) { error.value = e.response?.data?.message ?? t('Save failed'); }
}
async function activate() {
    await window.axios.post(`/api/admin/templates/${current.value.id}/activate`);
    current.value.status = 'ACTIVE'; notice.value = t('Published'); await load();
}
async function deactivate() {
    await window.axios.post(`/api/admin/templates/${current.value.id}/deactivate`);
    current.value.status = 'DISABLED'; notice.value = t('Disabled'); await load();
}
// Server-side render — the only way to preview a PDF (returns byte size) and to see the
// real engine output for a saved version.
async function previewOnServer() {
    if (!current.value?.id) { error.value = t('Save the draft first to render on the server'); return; }
    let sample = {}; try { sample = JSON.parse(sampleVars.value || '{}'); } catch { /* ignore */ }
    const { data } = await window.axios.post(`/api/admin/templates/${current.value.id}/preview`, { sample_data: sample });
    serverPreview.value = data;
}

onMounted(load);
</script>

<template>
    <Head :title="t('Template Studio')" />
    <AuthenticatedLayout>
        <template #header><h2 class="font-semibold text-xl text-gray-800">{{ t('Template Studio') }}</h2></template>

        <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4 flex gap-2">
                <button @click="tab = 'notifications'" :class="tab === 'notifications' ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-1 rounded border text-sm">{{ t('Notification templates') }}</button>
                <button @click="tab = 'invoices'" :class="tab === 'invoices' ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-1 rounded border text-sm">{{ t('Invoice layouts') }}</button>
            </div>

            <div v-if="error" class="mb-3 p-2 bg-red-100 text-red-700 rounded text-sm">{{ error }}</div>
            <div v-if="notice" class="mb-3 p-2 bg-green-100 text-green-700 rounded text-sm">{{ notice }}</div>

            <!-- Notification templates (DD `template` model) -->
            <div v-if="tab === 'notifications'" class="grid grid-cols-12 gap-4">
                <!-- list: purpose -> formats -> versions -->
                <div class="col-span-3 bg-white rounded shadow p-3">
                    <button @click="create" class="w-full mb-3 px-3 py-1 bg-indigo-600 text-white rounded text-sm">{{ t('+ New template') }}</button>
                    <div v-for="(byFormat, purpose) in grouped" :key="purpose" class="mb-3">
                        <div class="text-xs font-semibold text-gray-500">{{ purpose }}</div>
                        <template v-for="(versions, fmt) in byFormat" :key="fmt">
                            <button v-for="t in versions" :key="t.id" @click="open(t)"
                                class="block w-full text-left px-2 py-1 rounded text-sm hover:bg-gray-100"
                                :class="current && current.id === t.id ? 'bg-indigo-50' : ''">
                                <span class="inline-block px-1.5 rounded text-xs bg-gray-200">{{ fmt }}</span>
                                <span class="text-xs text-gray-400">{{ t.locale }} v{{ t.version }}</span>
                                <span class="ml-1" :class="t.status === 'ACTIVE' ? 'text-green-600' : (t.status === 'DISABLED' ? 'text-red-400' : 'text-gray-400')">●</span>
                            </button>
                        </template>
                    </div>
                </div>

                <!-- editor -->
                <div v-if="current" class="col-span-5 bg-white rounded shadow p-4 space-y-3">
                    <div class="flex gap-2">
                        <input v-model="current.template_purpose_code" :placeholder="t('PURPOSE_CODE')" class="border rounded px-2 py-1 text-sm flex-1" />
                        <select v-model="current.template_format" @change="onFormatChange" class="border rounded px-2 py-1 text-sm">
                            <option v-for="f in formats" :key="f">{{ f }}</option>
                        </select>
                        <input v-model="current.locale" class="border rounded px-2 py-1 text-sm w-16" />
                    </div>
                    <div class="text-xs text-gray-500">{{ t('engine:') }} <span class="font-mono">{{ current.engine_type }}</span><span v-if="current.version"> · v{{ current.version }}</span></div>
                    <textarea v-model="current.template_payload" rows="9" :placeholder="isPdf ? t('HTML source — rendered to PDF') : t('Body — use {{ variable }} placeholders')"
                        class="border rounded px-2 py-1 text-sm w-full font-mono"></textarea>
                    <div class="flex gap-2">
                        <button @click="save" class="px-3 py-1 bg-indigo-600 text-white rounded text-sm">{{ t('Save draft') }}</button>
                        <button @click="activate" :disabled="!current.id" class="px-3 py-1 bg-green-600 text-white rounded text-sm disabled:opacity-40">{{ t('Publish') }}</button>
                        <button @click="deactivate" :disabled="current.status !== 'ACTIVE'" class="px-3 py-1 bg-red-600 text-white rounded text-sm disabled:opacity-40">{{ t('Disable') }}</button>
                        <span class="text-xs self-center" :class="current.status === 'ACTIVE' ? 'text-green-600' : 'text-gray-400'">{{ current.status }}</span>
                    </div>
                    <p class="text-xs text-gray-400">{{ t('Each save creates the next DRAFT version; resolve() always prefers the highest ACTIVE version.') }}</p>
                </div>

                <!-- preview -->
                <div v-if="current" class="col-span-4 space-y-3">
                    <div class="bg-white rounded shadow p-3">
                        <div class="text-xs font-semibold text-gray-500 mb-1">{{ t('Sample variables (JSON)') }}</div>
                        <textarea v-model="sampleVars" rows="6" class="border rounded px-2 py-1 text-xs w-full font-mono"></textarea>
                        <button @click="previewOnServer" class="mt-2 px-2 py-1 bg-gray-800 text-white rounded text-xs">{{ t('Render on server') }}</button>
                    </div>
                    <div class="bg-gray-900 text-gray-100 rounded shadow p-3">
                        <div class="text-xs text-gray-400 mb-1">{{ t('Preview —') }} {{ current.template_format }}</div>
                        <div v-if="isPdf" class="text-sm">
                            <span v-if="serverPreview">{{ t('PDF rendered —') }} {{ serverPreview.bytes }} {{ t('bytes') }}</span>
                            <span v-else class="text-gray-500">{{ t('Save + “Render on server” to produce the PDF.') }}</span>
                        </div>
                        <pre v-else class="whitespace-pre-wrap text-sm">{{ serverPreview ? serverPreview.rendered : localPreview }}</pre>
                    </div>
                </div>
            </div>

            <!-- Invoice layouts -->
            <div v-else class="grid grid-cols-12 gap-4">
                <div class="col-span-4 bg-white rounded shadow p-3">
                    <div v-for="t in invoiceTemplates" :key="t.template_id" @click="currentInvoice = JSON.parse(JSON.stringify(t))"
                        class="px-2 py-1 rounded text-sm hover:bg-gray-100 cursor-pointer">
                        {{ t.name }} <span class="text-xs text-gray-400">({{ t.code }})</span>
                    </div>
                </div>
                <div v-if="currentInvoice" class="col-span-8 bg-white rounded shadow p-4">
                    <div class="font-semibold mb-2">{{ currentInvoice.name }}</div>
                    <pre class="bg-gray-50 rounded p-3 text-xs overflow-auto">{{ JSON.stringify(currentInvoice.layout, null, 2) }}</pre>
                    <p class="text-xs text-gray-500 mt-2">{{ t('Section toggles (header / billTo / lines / totals / footer) define the rendered invoice document.') }}</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
