<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import Drawer from '@/Components/Bss/Drawer.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import Timeline from '@/Components/Bss/Timeline.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

const { t, dateFmt } = useI18n();

// TCK-01 §13 / FE-APP-01 §7.3 backoffice cockpit: queues by status/priority with
// SLA-overdue indicators; detail drawer with the full timeline (comments, status
// changes, WO events), customer-visible/internal comment distinction, and the
// lifecycle actions (assign, create WO, resolve, reopen, cancel, close).
// Restyled onto the shared visual kit; the data layer is unchanged.
const tickets = ref([]);
const filter = ref({ status: '', queue: '', overdueOnly: false });
const current = ref(null);
const drawerOpen = ref(false);
const loading = ref(true);
const comment = ref({ body: '', visibility: 'INTERNAL' });
const assignee = ref('');
const resolveForm = ref({ resolution_code: 'RESOLVED_ON_SITE' });
const createForm = ref({ category: 'TECHNICAL', priority: 'NORMAL', subject: '', customer_id: '' });
const showCreate = ref(false);
const error = ref(null);
const flash = (e) => { error.value = e.response?.data?.message ?? t('Request failed'); setTimeout(() => (error.value = null), 6000); };

const STATUSES = ['OPEN', 'TRIAGED', 'ASSIGNED', 'WAITING_CUSTOMER', 'WAITING_INTERNAL', 'WAITING_WORK_ORDER', 'UNDER_REVIEW', 'RESOLVED', 'CLOSED', 'CANCELLED'];
const TERMINAL = ['RESOLVED', 'CLOSED', 'CANCELLED'];

// SLA-overdue logic — preserved exactly. (`tk` not `t`: keep the i18n helper free.)
const isOverdue = (tk) => tk.sla_due_at && !TERMINAL.includes(tk.status) && new Date(tk.sla_due_at) < new Date();
const visible = computed(() => (filter.value.overdueOnly ? tickets.value.filter(isOverdue) : tickets.value));

// --- summary tiles (derived from the already-loaded tickets) ------------------
const openCount = computed(() => tickets.value.filter((tk) => !TERMINAL.includes(tk.status)).length);
const unassignedCount = computed(() => tickets.value.filter((tk) => !tk.assignee_id && !TERMINAL.includes(tk.status)).length);
const breachedCount = computed(() => tickets.value.filter(isOverdue).length);
const resolvedCount = computed(() => tickets.value.filter((tk) => tk.status === 'RESOLVED').length);

// --- queue table -------------------------------------------------------------
const columns = [
    { key: 'status', label: 'Status' },
    { key: 'subject', label: 'Subject' },
    { key: 'priority', label: 'Priority' },
    { key: 'queue', label: 'Queue' },
    { key: 'assignee_id', label: 'Assignee' },
    { key: 'sla', label: 'SLA' },
];
const priorityClass = (p) => ({ URGENT: 'text-red-600 font-bold', HIGH: 'text-amber-600 font-semibold' }[p] ?? 'text-gray-500');

// --- detail timeline (status/WO events + comments → one Timeline) -------------
const timelineEvents = computed(() => {
    const c = current.value;
    if (!c) return [];
    const events = (c.timeline ?? []).map((e) => ({
        title: e.from_status ? `${e.from_status} → ${e.to_status}` : (e.event_type ?? t('Event')),
        subtitle: [e.from_status ? e.event_type : null, e.actor_id ? `${t('by')} ${e.actor_id}` : null].filter(Boolean).join(' · ') || undefined,
        at: dateFmt(e.created_at),
        tone: 'indigo',
        _ts: e.created_at,
    }));
    const comments = (c.comments ?? []).map((cm) => ({
        title: cm.body,
        subtitle: `${cm.visibility ?? 'INTERNAL'} · ${cm.author_id ?? t('system')}`,
        at: dateFmt(cm.created_at),
        tone: cm.visibility === 'CUSTOMER_VISIBLE' ? 'green' : 'gray',
        _ts: cm.created_at,
    }));
    return [...events, ...comments].sort((a, b) => String(b._ts ?? '').localeCompare(String(a._ts ?? '')));
});

const detailFields = computed(() => {
    const c = current.value ?? {};
    return [
        ['Category', c.category],
        ['Priority', c.priority],
        ['Queue', c.queue],
        ['Customer', c.customer_id],
        ['Work order', c.work_order_id],
        ['SLA due', c.sla_due_at ? dateFmt(c.sla_due_at) : null],
    ].filter(([, v]) => v);
});

async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get('/api/tickets', {
            params: { status: filter.value.status || undefined, queue: filter.value.queue || undefined, size: 50 },
        });
        tickets.value = data.items ?? [];
    } finally { loading.value = false; }
}
async function open(tk) {
    drawerOpen.value = true;
    const { data } = await window.axios.get(`/api/tickets/${tk.ticket_id}`);
    current.value = data;
}
async function act(action, payload = {}) {
    try {
        await window.axios.post(`/api/tickets/${current.value.ticket_id}/${action}`, payload);
        await open(current.value); await load();
    } catch (e) { flash(e); }
}
async function addComment() {
    if (!comment.value.body) return;
    await window.axios.post(`/api/tickets/${current.value.ticket_id}/comments`, comment.value);
    comment.value.body = '';
    await open(current.value);
}
async function createTicket() {
    try {
        await window.axios.post('/api/tickets', createForm.value, { headers: { 'Idempotency-Key': crypto.randomUUID() } });
        showCreate.value = false; createForm.value = { category: 'TECHNICAL', priority: 'NORMAL', subject: '', customer_id: '' };
        await load();
    } catch (e) { flash(e); }
}

onMounted(load);
</script>

<template>
    <Head :title="t('Tickets')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="Tickets" :crumbs="[{ label: 'Operations' }, { label: 'Tickets' }]">
                <template #actions>
                    <button class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50" @click="load">
                        {{ t('Refresh') }}
                    </button>
                    <button class="rounded-lg bg-op px-3 py-1.5 text-xs font-medium text-white hover:bg-op-dark" @click="showCreate = !showCreate">
                        {{ t('New ticket') }}
                    </button>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-5">
            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</p>

            <!-- Summary -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard label="Open" :value="openCount" sub="Active tickets" tone="indigo" :loading="loading" />
                <StatCard label="Unassigned" :value="unassignedCount" sub="Awaiting an owner" tone="amber" :loading="loading" />
                <StatCard label="SLA breached" :value="breachedCount" sub="Past SLA due" tone="red" :loading="loading" />
                <StatCard label="Resolved" :value="resolvedCount" sub="Awaiting close" tone="emerald" :loading="loading" />
            </div>

            <!-- Create ticket -->
            <Panel v-if="showCreate" title="New ticket" subtitle="Open a ticket in the cockpit">
                <template #actions>
                    <button class="rounded-lg border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50" @click="showCreate = false">
                        {{ t('Cancel') }}
                    </button>
                </template>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <input v-model="createForm.subject" :placeholder="t('Subject')" class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm sm:col-span-2" />
                    <input v-model="createForm.category" :placeholder="t('Category (catalog)')" class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm" />
                    <input v-model="createForm.customer_id" :placeholder="t('Customer ID')" class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm" />
                </div>
                <button class="mt-3 rounded-lg bg-op px-3 py-1.5 text-sm font-medium text-white hover:bg-op-dark" @click="createTicket">
                    {{ t('Create') }}
                </button>
            </Panel>

            <!-- Ticket queue -->
            <Panel title="Ticket queue" subtitle="Filter by status or queue — select a row for detail">
                <template #actions>
                    <select v-model="filter.status" @change="load" class="rounded-lg border border-gray-200 px-2 py-1 text-xs text-gray-700">
                        <option value="">{{ t('All statuses') }}</option>
                        <option v-for="s in STATUSES" :key="s" :value="s">{{ t(s.replaceAll('_', ' ')) }}</option>
                    </select>
                    <input v-model="filter.queue" @keyup.enter="load" :placeholder="t('Queue')" class="w-32 rounded-lg border border-gray-200 px-2 py-1 text-xs" />
                    <label class="flex items-center gap-1 text-xs text-gray-600">
                        <input type="checkbox" v-model="filter.overdueOnly" class="rounded border-gray-300 text-op focus:ring-op" />
                        {{ t('SLA overdue only') }}
                    </label>
                </template>

                <DataTable :columns="columns" :rows="visible" row-key="ticket_id"
                    :loading="loading" empty="No tickets match." @select="open">
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-subject="{ value }">
                        <span class="font-medium text-gray-800">{{ value }}</span>
                    </template>
                    <template #cell-priority="{ value }">
                        <span class="text-xs" :class="priorityClass(value)">{{ value }}</span>
                    </template>
                    <template #cell-queue="{ value }">{{ value ?? '—' }}</template>
                    <template #cell-assignee_id="{ value }">
                        <span v-if="value" class="text-gray-700">{{ value }}</span>
                        <span v-else class="text-xs text-amber-600">{{ t('unassigned') }}</span>
                    </template>
                    <template #cell-sla="{ row }">
                        <StatusBadge v-if="isOverdue(row)" status="SLA BREACHED" :map="{ 'SLA BREACHED': 'bg-red-100 text-red-700 ring-red-600/20' }" />
                        <span v-else-if="row.sla_due_at" class="text-xs text-gray-400">{{ dateFmt(row.sla_due_at) }}</span>
                        <span v-else class="text-xs text-gray-300">—</span>
                    </template>
                </DataTable>
            </Panel>
        </div>

        <!-- Ticket detail drawer -->
        <Drawer v-model:open="drawerOpen" title="Ticket" width="max-w-2xl">
            <div v-if="current" class="space-y-5">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-mono text-xs text-gray-400">{{ current.ticket_id }}</div>
                        <div class="text-base font-semibold text-gray-800">{{ current.subject }}</div>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <StatusBadge :status="current.status" />
                        <StatusBadge v-if="isOverdue(current)" status="SLA BREACHED" :map="{ 'SLA BREACHED': 'bg-red-100 text-red-700 ring-red-600/20' }" />
                    </div>
                </div>

                <!-- Details -->
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-100">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Details') }}</div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div v-for="[label, value] in detailFields" :key="label" class="min-w-0">
                            <dt class="text-xs text-gray-400">{{ t(label) }}</dt>
                            <dd class="truncate text-gray-700">{{ value }}</dd>
                        </div>
                    </dl>
                    <p v-if="!detailFields.length" class="text-sm text-gray-400">{{ t('No details.') }}</p>
                </div>

                <!-- Lifecycle actions -->
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-100">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Actions') }}</div>
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="assignee" :placeholder="t('Assignee ID')" class="w-32 rounded-lg border border-gray-200 px-2 py-1 text-xs" />
                        <button class="rounded-lg bg-op px-2.5 py-1 text-xs font-medium text-white hover:bg-op-dark" @click="act('assign', { assignee_id: assignee })">{{ t('Assign') }}</button>
                        <button class="rounded-lg bg-amber-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-amber-600" @click="act('work-orders', { tech_region_id: 'KE-NRB' })">{{ t('Create WO') }}</button>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <input v-model="resolveForm.resolution_code" class="w-44 rounded-lg border border-gray-200 px-2 py-1 text-xs" />
                        <button class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700" @click="act('resolve', resolveForm)">{{ t('Resolve') }}</button>
                        <button v-if="current.status === 'RESOLVED'" class="rounded-lg bg-blue-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-blue-600" @click="act('reopen', { reason_code: 'ISSUE_RECURRED' })">{{ t('Reopen') }}</button>
                        <button v-if="current.status === 'RESOLVED'" class="rounded-lg bg-gray-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-gray-700" @click="act('close')">{{ t('Close') }}</button>
                        <button class="rounded-lg bg-red-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-red-600" @click="act('cancel', { reason: 'duplicate' })">{{ t('Cancel') }}</button>
                    </div>
                </div>

                <!-- Add comment -->
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-100">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Add comment') }}</div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select v-model="comment.visibility" class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm">
                            <option value="INTERNAL">{{ t('INTERNAL') }}</option>
                            <option value="CUSTOMER_VISIBLE">{{ t('CUSTOMER_VISIBLE') }}</option>
                        </select>
                        <input v-model="comment.body" @keyup.enter="addComment" :placeholder="t('Add comment…')" class="min-w-0 flex-1 rounded-lg border border-gray-200 px-2 py-1.5 text-sm" />
                        <button class="rounded-lg bg-op px-3 py-1.5 text-sm font-medium text-white hover:bg-op-dark" @click="addComment">{{ t('Post') }}</button>
                    </div>
                </div>

                <!-- Activity timeline -->
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-100">
                    <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Timeline') }}</div>
                    <Timeline :events="timelineEvents" />
                </div>
            </div>
            <p v-else class="text-sm text-gray-400">{{ t('Select a ticket to see its timeline and actions.') }}</p>
        </Drawer>
    </AuthenticatedLayout>
</template>
