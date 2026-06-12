<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();
// Alias for use inside template scopes where `t` is shadowed by a ticket row variable.
const tr = t;

// TCK-01 §13 backoffice cockpit: queues by status/priority with SLA-overdue
// indicators; detail panel with the full timeline (comments, status changes, WO
// events), customer-visible/internal comment distinction, and the lifecycle
// actions (assign, create WO, resolve, reopen, cancel, close).
const tickets = ref([]);
const filter = ref({ status: '', queue: '', overdueOnly: false });
const current = ref(null);
const comment = ref({ body: '', visibility: 'INTERNAL' });
const assignee = ref('');
const resolveForm = ref({ resolution_code: 'RESOLVED_ON_SITE' });
const createForm = ref({ category: 'TECHNICAL', priority: 'NORMAL', subject: '', customer_id: '' });
const showCreate = ref(false);
const error = ref(null);
const flash = (e) => { error.value = e.response?.data?.message ?? t('Request failed'); setTimeout(() => (error.value = null), 6000); };

const isOverdue = (t) => t.sla_due_at && !['RESOLVED', 'CLOSED', 'CANCELLED'].includes(t.status) && new Date(t.sla_due_at) < new Date();
const visible = computed(() => (filter.value.overdueOnly ? tickets.value.filter(isOverdue) : tickets.value));
const statusChip = (s) => ({ OPEN: 'bg-blue-100 text-blue-700', ASSIGNED: 'bg-op-soft text-op', WAITING_WORK_ORDER: 'bg-amber-100 text-amber-700', WAITING_CUSTOMER: 'bg-amber-100 text-amber-700', WAITING_INTERNAL: 'bg-amber-100 text-amber-700', UNDER_REVIEW: 'bg-purple-100 text-purple-700', RESOLVED: 'bg-green-100 text-green-700', CLOSED: 'bg-gray-200 text-gray-600', CANCELLED: 'bg-red-100 text-red-600' }[s] ?? 'bg-gray-100');

async function load() {
    const { data } = await window.axios.get('/api/tickets', {
        params: { status: filter.value.status || undefined, queue: filter.value.queue || undefined, size: 50 },
    });
    tickets.value = data.items ?? [];
}
async function open(t) {
    const { data } = await window.axios.get(`/api/tickets/${t.ticket_id}`);
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
        <template #header><h2 class="text-xl font-semibold text-gray-800">{{ t('Ticketing cockpit (TCK-01)') }}</h2></template>

        <div class="py-6 mx-auto max-w-7xl sm:px-6 lg:px-8">
            <p v-if="error" class="mb-3 p-2 bg-red-100 text-red-700 rounded text-sm">{{ error }}</p>

            <div class="mb-3 flex gap-2 items-center">
                <select v-model="filter.status" @change="load" class="border rounded px-2 py-1 text-sm">
                    <option value="">{{ t('all statuses') }}</option>
                    <option v-for="s in ['OPEN', 'TRIAGED', 'ASSIGNED', 'WAITING_CUSTOMER', 'WAITING_INTERNAL', 'WAITING_WORK_ORDER', 'UNDER_REVIEW', 'RESOLVED', 'CLOSED', 'CANCELLED']" :key="s">{{ s }}</option>
                </select>
                <input v-model="filter.queue" @keyup.enter="load" :placeholder="t('queue')" class="border rounded px-2 py-1 text-sm w-36" />
                <label class="text-sm"><input type="checkbox" v-model="filter.overdueOnly" /> {{ t('SLA overdue only') }}</label>
                <button @click="showCreate = !showCreate" class="ml-auto px-3 py-1 bg-op text-white rounded text-sm">{{ t('+ New ticket') }}</button>
            </div>

            <div v-if="showCreate" class="bg-white rounded shadow p-3 mb-3 grid grid-cols-5 gap-2">
                <input v-model="createForm.subject" :placeholder="t('Subject')" class="border rounded px-2 py-1 text-sm col-span-2" />
                <input v-model="createForm.category" :placeholder="t('Category (catalog)')" class="border rounded px-2 py-1 text-sm" />
                <input v-model="createForm.customer_id" :placeholder="t('customer id')" class="border rounded px-2 py-1 text-sm" />
                <button @click="createTicket" class="px-3 py-1 bg-op text-white rounded text-sm">{{ t('Create') }}</button>
            </div>

            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-5 bg-white rounded shadow divide-y max-h-[40rem] overflow-auto">
                    <div v-for="t in visible" :key="t.ticket_id" @click="open(t)"
                        class="p-2.5 cursor-pointer hover:bg-op-soft" :class="current?.ticket_id === t.ticket_id ? 'bg-op-soft' : ''">
                        <div class="flex items-center gap-2">
                            <span class="text-xs px-1.5 py-0.5 rounded" :class="statusChip(t.status)">{{ t.status }}</span>
                            <span class="font-medium text-sm truncate flex-1">{{ t.subject }}</span>
                            <span class="text-xs" :class="{ URGENT: 'text-red-600 font-bold', HIGH: 'text-amber-600 font-semibold' }[t.priority]">{{ t.priority }}</span>
                        </div>
                        <div class="text-xs text-gray-400 flex gap-2 mt-0.5">
                            <span>{{ t.queue ?? '—' }}</span>
                            <span>{{ t.assignee_id ?? tr('unassigned') }}</span>
                            <span v-if="isOverdue(t)" class="text-red-600 font-semibold">⏰ {{ tr('SLA BREACHED') }}</span>
                            <span v-else-if="t.sla_due_at">{{ tr('due') }} {{ t.sla_due_at }}</span>
                        </div>
                    </div>
                    <div v-if="!visible.length" class="p-4 text-sm text-gray-400">{{ t('No tickets match.') }}</div>
                </div>

                <div v-if="current" class="col-span-7 bg-white rounded shadow p-4 space-y-3 max-h-[40rem] overflow-auto">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold">{{ current.subject }}</span>
                        <span class="text-xs px-1.5 py-0.5 rounded" :class="statusChip(current.status)">{{ current.status }}</span>
                        <span v-if="isOverdue(current)" class="text-xs text-red-600 font-semibold">⏰ {{ t('SLA breached') }}</span>
                        <span class="text-xs text-gray-400 font-mono ml-auto">{{ current.ticket_id }}</span>
                    </div>
                    <div class="text-xs text-gray-500">
                        {{ current.category }} · {{ current.priority }} · {{ t('queue') }} {{ current.queue ?? '—' }} ·
                        {{ t('customer') }} {{ current.customer_id ?? '—' }} ·
                        <span v-if="current.work_order_id">{{ t('WO') }} <span class="font-mono">{{ current.work_order_id }}</span></span>
                    </div>

                    <div class="flex flex-wrap gap-1 items-center border-y py-2">
                        <input v-model="assignee" :placeholder="t('assignee id')" class="border rounded px-1.5 py-0.5 text-xs w-28" />
                        <button @click="act('assign', { assignee_id: assignee })" class="px-2 py-0.5 bg-op text-white rounded text-xs">{{ t('Assign') }}</button>
                        <button @click="act('work-orders', { tech_region_id: 'KE-NRB' })" class="px-2 py-0.5 bg-amber-500 text-white rounded text-xs">{{ t('Create WO') }}</button>
                        <input v-model="resolveForm.resolution_code" class="border rounded px-1.5 py-0.5 text-xs w-40" />
                        <button @click="act('resolve', resolveForm)" class="px-2 py-0.5 bg-green-600 text-white rounded text-xs">{{ t('Resolve') }}</button>
                        <button v-if="current.status === 'RESOLVED'" @click="act('reopen', { reason_code: 'ISSUE_RECURRED' })" class="px-2 py-0.5 bg-blue-500 text-white rounded text-xs">{{ t('Reopen') }}</button>
                        <button v-if="current.status === 'RESOLVED'" @click="act('close')" class="px-2 py-0.5 bg-gray-600 text-white rounded text-xs">{{ t('Close') }}</button>
                        <button @click="act('cancel', { reason: 'duplicate' })" class="px-2 py-0.5 bg-red-500 text-white rounded text-xs">{{ t('Cancel') }}</button>
                    </div>

                    <div>
                        <div class="text-xs font-semibold text-gray-500 uppercase mb-1">{{ t('Comments') }}</div>
                        <div class="flex gap-1 mb-2">
                            <select v-model="comment.visibility" class="border rounded px-1 py-0.5 text-xs">
                                <option>INTERNAL</option><option>CUSTOMER_VISIBLE</option>
                            </select>
                            <input v-model="comment.body" @keyup.enter="addComment" :placeholder="t('Add comment…')" class="border rounded px-2 py-1 text-sm flex-1" />
                            <button @click="addComment" class="px-2 py-1 bg-op text-white rounded text-xs">{{ t('Post') }}</button>
                        </div>
                        <div v-for="c in current.comments ?? []" :key="c.id" class="text-sm border-t py-1">
                            <span class="text-xs px-1 rounded" :class="c.visibility === 'CUSTOMER_VISIBLE' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500'">{{ c.visibility ?? 'INTERNAL' }}</span>
                            {{ c.body }}
                            <span class="text-xs text-gray-400">— {{ c.author_id ?? t('system') }}, {{ c.created_at }}</span>
                        </div>
                    </div>

                    <div>
                        <div class="text-xs font-semibold text-gray-500 uppercase mb-1">{{ t('Timeline') }}</div>
                        <div class="border-l-2 border-op pl-3 space-y-1">
                            <div v-for="e in current.timeline ?? []" :key="e.id" class="text-xs">
                                <span class="text-gray-400">{{ e.created_at }}</span>
                                <span class="font-semibold ml-1">{{ e.event_type }}</span>
                                <span v-if="e.from_status" class="text-gray-500 ml-1">{{ e.from_status }} → {{ e.to_status }}</span>
                                <span v-if="e.actor_id" class="text-gray-400 ml-1">{{ t('by') }} {{ e.actor_id }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="col-span-7 bg-white rounded shadow p-8 text-center text-sm text-gray-400">
                    {{ t('Select a ticket to see its timeline and actions.') }}
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
