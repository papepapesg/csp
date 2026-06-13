<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
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
const flash = (msg) => { notice.value = msg; setTimeout(() => (notice.value = null), 4000); };

const invoiceTemplates = ref([]);
const currentInvoice = ref(null);

const engineFor = (f) => (f === 'PDF' ? 'HTML_TO_PDF' : 'HANDLEBARS');
const blank = () => ({ template_purpose_code: '', template_format: 'SMS_TEXT', locale: 'en', engine_type: 'HANDLEBARS', template_payload: '', status: 'DRAFT', version: null, id: null });

// Group by purpose code, then by format — each entry is the version list for that cell.
const grouped = computed(() => {
    const m = {};
    for (const tpl of templates.value) {
        (m[tpl.template_purpose_code] ??= {});
        (m[tpl.template_purpose_code][tpl.template_format] ??= []).push(tpl);
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
function open(tpl) { current.value = JSON.parse(JSON.stringify(tpl)); serverPreview.value = null; }
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
        flash(t('Saved draft v:v', { v: data.version })); await load();
    } catch (e) { error.value = e.response?.data?.message ?? t('Save failed'); }
}
async function activate() {
    await window.axios.post(`/api/admin/templates/${current.value.id}/activate`);
    current.value.status = 'ACTIVE'; flash(t('Published')); await load();
}
async function deactivate() {
    await window.axios.post(`/api/admin/templates/${current.value.id}/deactivate`);
    current.value.status = 'DISABLED'; flash(t('Disabled')); await load();
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
        <template #header>
            <PageHeader :title="t('Templates')" :crumbs="[{ label: 'Templates' }, { label: tab === 'invoices' ? 'Invoice layouts' : 'Notification templates' }]">
                <template #actions>
                    <button v-if="tab === 'notifications'" @click="create" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">+ {{ t('New template') }}</button>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <div class="inline-flex gap-1 rounded-xl bg-gray-100 p-1">
                <button @click="tab = 'notifications'" class="rounded-lg px-3 py-1.5 text-sm font-medium transition" :class="tab === 'notifications' ? 'bg-white text-op shadow-sm' : 'text-gray-500 hover:text-gray-700'">{{ t('Notification templates') }}</button>
                <button @click="tab = 'invoices'" class="rounded-lg px-3 py-1.5 text-sm font-medium transition" :class="tab === 'invoices' ? 'bg-white text-op shadow-sm' : 'text-gray-500 hover:text-gray-700'">{{ t('Invoice layouts') }}</button>
            </div>

            <div v-if="error" class="rounded-lg bg-red-50 p-2 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</div>
            <div v-if="notice" class="rounded-lg bg-emerald-50 p-2 text-sm text-emerald-700 ring-1 ring-emerald-100">{{ notice }}</div>

            <!-- Notification templates (DD `template` model) -->
            <div v-if="tab === 'notifications'" class="grid grid-cols-12 gap-5">
                <!-- list: purpose -> formats -> versions -->
                <div class="col-span-12 lg:col-span-3">
                    <Panel :title="t('Templates')">
                        <div v-for="(byFormat, purpose) in grouped" :key="purpose" class="mb-3">
                            <div class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ purpose }}</div>
                            <template v-for="(versions, fmt) in byFormat" :key="fmt">
                                <button v-for="tpl in versions" :key="tpl.id" @click="open(tpl)"
                                    class="mb-0.5 flex w-full items-center gap-1 rounded-lg px-2 py-1 text-left hover:bg-gray-50"
                                    :class="current && current.id === tpl.id ? 'bg-op-soft' : ''">
                                    <span class="rounded bg-gray-100 px-1.5 text-xs">{{ fmt }}</span>
                                    <span class="text-xs text-gray-400">{{ tpl.locale }} v{{ tpl.version }}</span>
                                    <span class="ml-auto"><StatusBadge :status="tpl.status" /></span>
                                </button>
                            </template>
                        </div>
                        <p v-if="!Object.keys(grouped).length" class="text-sm text-gray-400">{{ t('No templates yet.') }}</p>
                    </Panel>
                </div>

                <!-- editor -->
                <div v-if="current" class="col-span-12 lg:col-span-5">
                    <Panel :title="t('Editor')">
                        <template #actions><StatusBadge :status="current.status" /></template>
                        <div class="mb-2 flex gap-2">
                            <input v-model="current.template_purpose_code" :placeholder="t('PURPOSE_CODE')" class="flex-1 rounded-md border-gray-300 text-sm" />
                            <select v-model="current.template_format" @change="onFormatChange" class="rounded-md border-gray-300 text-sm">
                                <option v-for="f in formats" :key="f">{{ f }}</option>
                            </select>
                            <input v-model="current.locale" class="w-16 rounded-md border-gray-300 text-sm" />
                        </div>
                        <div class="mb-2 text-xs text-gray-500">{{ t('engine:') }} <span class="font-mono">{{ current.engine_type }}</span><span v-if="current.version"> · v{{ current.version }}</span></div>
                        <textarea v-model="current.template_payload" rows="10" :placeholder="isPdf ? t('HTML source — rendered to PDF') : t('Body — use {{ variable }} placeholders')"
                            class="w-full rounded-md border-gray-300 font-mono text-sm"></textarea>
                        <div class="mt-3 flex gap-2">
                            <button @click="save" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ t('Save draft') }}</button>
                            <button @click="activate" :disabled="!current.id" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm text-white disabled:opacity-40">{{ t('Publish') }}</button>
                            <button @click="deactivate" :disabled="current.status !== 'ACTIVE'" class="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white disabled:opacity-40">{{ t('Disable') }}</button>
                        </div>
                        <p class="mt-2 text-xs text-gray-400">{{ t('Each save creates the next DRAFT version; resolve() always prefers the highest ACTIVE version.') }}</p>
                    </Panel>
                </div>

                <!-- preview -->
                <div v-if="current" class="col-span-12 space-y-4 lg:col-span-4">
                    <Panel :title="t('Sample variables (JSON)')">
                        <textarea v-model="sampleVars" rows="6" class="w-full rounded-md border-gray-300 font-mono text-xs"></textarea>
                        <button @click="previewOnServer" class="mt-2 rounded-md bg-gray-800 px-3 py-1.5 text-xs text-white hover:bg-gray-700">{{ t('Render on server') }}</button>
                    </Panel>
                    <div class="rounded-xl bg-gray-900 p-4 text-gray-100 shadow-sm">
                        <div class="mb-1.5 text-xs text-gray-400">{{ t('Preview —') }} {{ current.template_format }}</div>
                        <div v-if="isPdf" class="text-sm">
                            <span v-if="serverPreview">{{ t('PDF rendered —') }} {{ serverPreview.bytes }} {{ t('bytes') }}</span>
                            <span v-else class="text-gray-500">{{ t('Save + “Render on server” to produce the PDF.') }}</span>
                        </div>
                        <pre v-else class="whitespace-pre-wrap text-sm">{{ serverPreview ? serverPreview.rendered : localPreview }}</pre>
                    </div>
                </div>
            </div>

            <!-- Invoice layouts -->
            <div v-else class="grid grid-cols-12 gap-5">
                <div class="col-span-12 lg:col-span-4">
                    <Panel :title="t('Invoice layouts')">
                        <div v-for="tpl in invoiceTemplates" :key="tpl.template_id" @click="currentInvoice = JSON.parse(JSON.stringify(tpl))"
                            class="cursor-pointer rounded-lg px-2 py-1.5 text-sm hover:bg-gray-50"
                            :class="currentInvoice && currentInvoice.template_id === tpl.template_id ? 'bg-op-soft' : ''">
                            {{ tpl.name }} <span class="text-xs text-gray-400">({{ tpl.code }})</span>
                        </div>
                        <p v-if="!invoiceTemplates.length" class="text-sm text-gray-400">{{ t('No invoice layouts.') }}</p>
                    </Panel>
                </div>
                <div v-if="currentInvoice" class="col-span-12 lg:col-span-8">
                    <Panel :title="currentInvoice.name" :subtitle="currentInvoice.code">
                        <pre class="overflow-auto rounded-lg bg-gray-50 p-3 text-xs ring-1 ring-gray-100">{{ JSON.stringify(currentInvoice.layout, null, 2) }}</pre>
                        <p class="mt-2 text-xs text-gray-500">{{ t('Section toggles (header / billTo / lines / totals / footer) define the rendered invoice document.') }}</p>
                    </Panel>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
