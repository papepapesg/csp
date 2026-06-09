<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';

// Template Studio (NOT-01). Design notification messages per CHANNEL — the same
// template_code (e.g. SUBSCRIPTION_ACTIVATED) renders a terse SMS vs a full EMAIL.
// {{placeholders}} fill from the event payload at send time. Authored DRAFT -> ACTIVE.
const channels = ['SMS', 'EMAIL', 'PUSH', 'WHATSAPP'];
const tab = ref('notifications'); // notifications | invoices

const templates = ref([]);
const current = ref(null);
const preview = ref({ subject: null, body: null });
const sampleVars = ref('{\n  "customerName": "Sarah",\n  "subscriptionId": "sub_123"\n}');
const error = ref(null);
const notice = ref(null);

const invoiceTemplates = ref([]);
const currentInvoice = ref(null);

const blank = () => ({ template_code: '', channel: 'SMS', locale: 'en', subject: '', body: '', variables: [], status: 'DRAFT' });

const grouped = computed(() => {
    const m = {};
    for (const t of templates.value) (m[t.template_code] ??= []).push(t);
    return m;
});
const needsSubject = computed(() => current.value && ['EMAIL', 'PUSH'].includes(current.value.channel));

async function load() {
    const { data } = await window.axios.get('/api/notification-templates');
    templates.value = data.items ?? [];
    const inv = await window.axios.get('/api/invoice-templates');
    invoiceTemplates.value = inv.data.items ?? [];
}
function open(t) { current.value = JSON.parse(JSON.stringify(t)); refreshPreview(); }
function create() { current.value = blank(); preview.value = { subject: null, body: null }; }

async function refreshPreview() {
    if (!current.value) return;
    try {
        const vars = JSON.parse(sampleVars.value || '{}');
        const { data } = await window.axios.post('/api/notification-templates/preview', {
            template_code: current.value.template_code, channel: current.value.channel, variables: vars, locale: current.value.locale,
        });
        // Live local render too (so unsaved edits show immediately).
        preview.value = { subject: localRender(current.value.subject, vars), body: localRender(current.value.body, vars) || data.body };
    } catch (e) { preview.value = { subject: null, body: localRender(current.value.body, {}) }; }
}
function localRender(text, vars) {
    return (text || '').replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, k) => (vars[k] ?? `{{${k}}}`));
}

async function save() {
    error.value = notice.value = null;
    try {
        const payload = {
            template_code: current.value.template_code, channel: current.value.channel, locale: current.value.locale,
            subject: current.value.subject || null, body: current.value.body, variables: current.value.variables,
        };
        if (current.value.template_id) {
            await window.axios.patch(`/api/notification-templates/${current.value.template_id}`, payload);
        } else {
            const { data } = await window.axios.post('/api/notification-templates', payload);
            current.value.template_id = data.template_id;
        }
        notice.value = 'Saved'; await load();
    } catch (e) { error.value = e.response?.data?.message ?? 'Save failed'; }
}
async function activate() {
    await window.axios.post(`/api/notification-templates/${current.value.template_id}/activate`);
    current.value.status = 'ACTIVE'; notice.value = 'Published'; await load();
}

onMounted(load);
</script>

<template>
    <Head title="Template Studio" />
    <AuthenticatedLayout>
        <template #header><h2 class="font-semibold text-xl text-gray-800">Template Studio</h2></template>

        <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4 flex gap-2">
                <button @click="tab = 'notifications'" :class="tab === 'notifications' ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-1 rounded border text-sm">Notification templates</button>
                <button @click="tab = 'invoices'" :class="tab === 'invoices' ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-1 rounded border text-sm">Invoice layouts</button>
            </div>

            <div v-if="error" class="mb-3 p-2 bg-red-100 text-red-700 rounded text-sm">{{ error }}</div>
            <div v-if="notice" class="mb-3 p-2 bg-green-100 text-green-700 rounded text-sm">{{ notice }}</div>

            <!-- Notification templates -->
            <div v-if="tab === 'notifications'" class="grid grid-cols-12 gap-4">
                <!-- list -->
                <div class="col-span-3 bg-white rounded shadow p-3">
                    <button @click="create" class="w-full mb-3 px-3 py-1 bg-indigo-600 text-white rounded text-sm">+ New template</button>
                    <div v-for="(variants, code) in grouped" :key="code" class="mb-3">
                        <div class="text-xs font-semibold text-gray-500">{{ code }}</div>
                        <button v-for="t in variants" :key="t.template_id" @click="open(t)"
                            class="block w-full text-left px-2 py-1 rounded text-sm hover:bg-gray-100"
                            :class="current && current.template_id === t.template_id ? 'bg-indigo-50' : ''">
                            <span class="inline-block px-1.5 rounded text-xs bg-gray-200">{{ t.channel }}</span>
                            <span class="ml-1" :class="t.status === 'ACTIVE' ? 'text-green-600' : 'text-gray-400'">●</span>
                        </button>
                    </div>
                </div>

                <!-- editor -->
                <div v-if="current" class="col-span-5 bg-white rounded shadow p-4 space-y-3">
                    <div class="flex gap-2">
                        <input v-model="current.template_code" placeholder="TEMPLATE_CODE" class="border rounded px-2 py-1 text-sm flex-1" />
                        <select v-model="current.channel" @change="refreshPreview" class="border rounded px-2 py-1 text-sm">
                            <option v-for="c in channels" :key="c">{{ c }}</option>
                        </select>
                        <input v-model="current.locale" class="border rounded px-2 py-1 text-sm w-16" />
                    </div>
                    <input v-if="needsSubject" v-model="current.subject" @input="refreshPreview" placeholder="Subject (EMAIL/PUSH)" class="border rounded px-2 py-1 text-sm w-full" />
                    <textarea v-model="current.body" @input="refreshPreview" rows="8" placeholder="Body — use {{ variable }} placeholders"
                        class="border rounded px-2 py-1 text-sm w-full font-mono"></textarea>
                    <div class="flex gap-2">
                        <button @click="save" class="px-3 py-1 bg-indigo-600 text-white rounded text-sm">Save draft</button>
                        <button @click="activate" :disabled="!current.template_id" class="px-3 py-1 bg-green-600 text-white rounded text-sm disabled:opacity-40">Publish</button>
                        <span class="text-xs self-center" :class="current.status === 'ACTIVE' ? 'text-green-600' : 'text-gray-400'">{{ current.status }}</span>
                    </div>
                </div>

                <!-- live preview -->
                <div v-if="current" class="col-span-4 space-y-3">
                    <div class="bg-white rounded shadow p-3">
                        <div class="text-xs font-semibold text-gray-500 mb-1">Sample variables (JSON)</div>
                        <textarea v-model="sampleVars" @input="refreshPreview" rows="5" class="border rounded px-2 py-1 text-xs w-full font-mono"></textarea>
                    </div>
                    <div class="bg-gray-900 text-gray-100 rounded shadow p-3">
                        <div class="text-xs text-gray-400 mb-1">Preview — {{ current.channel }}</div>
                        <div v-if="preview.subject" class="font-semibold mb-1">{{ preview.subject }}</div>
                        <pre class="whitespace-pre-wrap text-sm">{{ preview.body }}</pre>
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
                    <p class="text-xs text-gray-500 mt-2">Section toggles (header / billTo / lines / totals / footer) define the rendered invoice document.</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
