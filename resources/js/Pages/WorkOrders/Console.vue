<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import Drawer from '@/Components/Bss/Drawer.vue';
import StageTracker from '@/Components/Bss/StageTracker.vue';
import Timeline from '@/Components/Bss/Timeline.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

// FE-APP-01 §11 Work Orders backoffice over the WO-01 backend. A dispatch board (kanban of
// status columns) is the centerpiece, a queue table feeds a per-WO drawer with the lifecycle
// stage tracker, an assignment action, the finalization-review action and the activity history.
// All copy flows through t(); every call is wrapped and degrades to a graceful empty state.
const { t, dateFmt } = useI18n();

// One list-shape normalizer (SOPHIX paginated → items; tolerant of {data}/array too).
const rows = (d) => (Array.isArray(d) ? d : (d?.items ?? d?.data ?? []));

// WO-01 lifecycle, in order. The board renders the four live (non-terminal) lanes.
const LANES = [
    ['PENDING', 'Pending', 'amber'],
    ['ASSIGNED', 'Assigned', 'blue'],
    ['IN_PROGRESS', 'In progress', 'indigo'],
    ['FINALIZATION_PENDING', 'Finalization', 'cyan'],
];
const LIFECYCLE = ['PENDING', 'ASSIGNED', 'IN_PROGRESS', 'FINALIZATION_PENDING', 'COMPLETED'];

const loading = ref(true);
const error = ref(null);
const notice = ref(null);
const all = ref([]);

const flash = (m) => { notice.value = m; setTimeout(() => (notice.value = null), 3500); };
const fail = (e) => { error.value = e?.response?.data?.message ?? t('Request failed'); setTimeout(() => (error.value = null), 6000); };

async function load() {
    loading.value = true;
    try {
        // Pull a generous page; the board/table partition client-side by status.
        const { data } = await window.axios.get('/api/work-orders', { params: { size: 200 } });
        all.value = rows(data);
    } catch (e) { all.value = []; /* graceful empty board */ } finally { loading.value = false; }
}
onMounted(load);

// --- derived board + summary -------------------------------------------------
const byLane = computed(() => {
    const m = Object.fromEntries(LANES.map(([k]) => [k, []]));
    for (const wo of all.value) if (m[wo.status]) m[wo.status].push(wo);
    return m;
});
const backlog = computed(() => all.value.filter((w) => ['PENDING', 'ASSIGNED', 'IN_PROGRESS', 'FINALIZATION_PENDING'].includes(w.status)).length);
const unassigned = computed(() => all.value.filter((w) => w.status === 'PENDING' && !assigneeOf(w)).length);
const inFinalization = computed(() => all.value.filter((w) => w.status === 'FINALIZATION_PENDING').length);

const assigneeOf = (wo) => wo.assigned_technician_id || wo.contractor_id || wo.team_id || null;
const customerOf = (wo) => wo.account_id || wo.customer_id || wo.homepass_id || wo.subscription_id || null;
const shortId = (id) => (id ? String(id).slice(-8) : '—');

// --- queue table -------------------------------------------------------------
const columns = [
    { key: 'work_order_id', label: 'Work order' },
    { key: 'type', label: 'Type' },
    { key: 'customer', label: 'Customer / Site' },
    { key: 'status', label: 'Status' },
    { key: 'assignee', label: 'Assignee' },
];
const tableRows = computed(() => all.value.map((wo) => ({
    ...wo,
    customer: customerOf(wo),
    assignee: assigneeOf(wo),
})));

// --- drawer ------------------------------------------------------------------
const open = ref(false);
const detail = ref(null);
const detailLoading = ref(false);
const assignForm = ref({ assigned_technician_id: '', contractor_id: '', team_id: '' });

async function openWo(wo) {
    open.value = true;
    detail.value = wo;
    detailLoading.value = true;
    assignForm.value = { assigned_technician_id: '', contractor_id: '', team_id: '' };
    try {
        const { data } = await window.axios.get(`/api/work-orders/${wo.work_order_id}`);
        detail.value = data?.data ?? data ?? wo;
    } catch (e) { /* keep the list row as a fallback detail */ } finally { detailLoading.value = false; }
}

// Lifecycle stages for the StageTracker, resolved against the current WO status.
const stages = computed(() => {
    const wo = detail.value;
    if (!wo) return [];
    if (wo.status === 'CANCELLED') {
        return [{ key: 'CANCELLED', label: 'Cancelled', state: 'failed', at: when(wo, 'CANCELLED') }];
    }
    const cur = LIFECYCLE.indexOf(wo.status);
    return LIFECYCLE.map((code, i) => ({
        key: code,
        label: { PENDING: 'Pending', ASSIGNED: 'Assigned', IN_PROGRESS: 'In progress', FINALIZATION_PENDING: 'Finalization', COMPLETED: 'Completed' }[code],
        state: i < cur ? 'done' : i === cur ? 'current' : 'pending',
        at: when(wo, code),
    }));
});

// Find the timestamp a WO entered a status from its status history (when available).
function when(wo, code) {
    const h = (wo.status_history ?? wo.statusHistory ?? []).find((e) => (e.new_status ?? e.newStatus) === code);
    const at = h?.changed_at ?? h?.changedAt;
    return at ? dateFmt(at) : undefined;
}

// Assignment + status activity → one Timeline, newest first.
const history = computed(() => {
    const wo = detail.value;
    if (!wo) return [];
    const status = (wo.status_history ?? wo.statusHistory ?? []).map((e) => ({
        title: `${e.prev_status ?? e.prevStatus ?? '—'} → ${e.new_status ?? e.newStatus ?? '—'}`,
        subtitle: [e.reason, e.changed_by ?? e.changedBy].filter(Boolean).join(' · ') || undefined,
        at: dateFmt(e.changed_at ?? e.changedAt),
        tone: 'indigo',
        _ts: e.changed_at ?? e.changedAt,
    }));
    const assign = (wo.assignment_history ?? wo.assignmentHistory ?? []).map((e) => ({
        title: t('Reassigned'),
        subtitle: [e.assigned_technician_id ?? e.contractor_id ?? e.team_id, e.reason].filter(Boolean).join(' · ') || undefined,
        at: dateFmt(e.created_at ?? e.changed_at),
        tone: 'amber',
        _ts: e.created_at ?? e.changed_at,
    }));
    return [...status, ...assign].sort((a, b) => String(b._ts ?? '').localeCompare(String(a._ts ?? '')));
});

const detailFields = computed(() => {
    const wo = detail.value ?? {};
    return [
        ['Type', wo.type],
        ['Kind', wo.kind],
        ['Job type', wo.job_type_code],
        ['Priority', wo.priority],
        ['Customer / Site', customerOf(wo)],
        ['Subscription', wo.subscription_id],
        ['Tech region', wo.tech_region_id],
        ['Source', [wo.source_type, wo.source_ref].filter(Boolean).join(' · ') || null],
        ['SLA due', wo.sla_due_at ? dateFmt(wo.sla_due_at) : null],
        ['Scheduled', wo.scheduled_at ? dateFmt(wo.scheduled_at) : null],
        ['Reason', wo.initial_reason ?? wo.final_reason],
    ].filter(([, v]) => v);
});

const canAssign = computed(() => ['PENDING', 'ASSIGNED', 'IN_PROGRESS'].includes(detail.value?.status));
const canFinalize = computed(() => detail.value?.status === 'FINALIZATION_PENDING');
const assignFilled = computed(() => Object.values(assignForm.value).some((v) => String(v).trim()));

async function assign() {
    if (!detail.value || !assignFilled.value) return;
    try {
        const payload = Object.fromEntries(Object.entries(assignForm.value).filter(([, v]) => String(v).trim()));
        await window.axios.post(`/api/work-orders/${detail.value.work_order_id}/assign`, payload);
        flash(t('Work order assigned'));
        await openWo(detail.value);
        await load();
    } catch (e) { fail(e); }
}

// WO-01 §4.4 finalization review: second-confirm runs the checklist → COMPLETED.
async function approveFinalization() {
    if (!detail.value) return;
    try {
        await window.axios.post(`/api/work-orders/${detail.value.work_order_id}/finalize-second-confirm`, {});
        flash(t('Finalization approved'));
        await openWo(detail.value);
        await load();
    } catch (e) { fail(e); }
}

const laneTone = (key) => Object.fromEntries(LANES.map(([k, , tone]) => [k, tone]))[key];
const laneAccent = {
    amber: 'border-t-amber-400', blue: 'border-t-blue-400',
    indigo: 'border-t-op', cyan: 'border-t-cyan-400',
};
</script>

<template>
    <Head :title="t('Work Orders')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="Work Orders" :crumbs="[{ label: 'Operations' }, { label: 'Work Orders' }]">
                <template #actions>
                    <button class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50" @click="load">
                        {{ t('Refresh') }}
                    </button>
                </template>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-7xl space-y-5">
            <p v-if="notice" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 ring-1 ring-emerald-100">{{ notice }}</p>
            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</p>

            <!-- Summary -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard label="Backlog" :value="backlog" sub="Open work orders" tone="indigo" :loading="loading" />
                <StatCard label="Unassigned" :value="unassigned" sub="Awaiting dispatch" tone="amber" :loading="loading" />
                <StatCard label="In finalization" :value="inFinalization" sub="Pending review" tone="cyan" :loading="loading" />
            </div>

            <!-- Dispatch board (centerpiece) -->
            <Panel title="Dispatch board" subtitle="Live work orders by lifecycle status (WO-01)">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div v-for="[key, label, tone] in LANES" :key="key"
                        class="flex flex-col rounded-xl border-t-4 bg-gray-50/70 ring-1 ring-gray-100" :class="laneAccent[tone]">
                        <div class="flex items-center justify-between px-3 py-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-600">{{ t(label) }}</span>
                            <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-gray-500 ring-1 ring-gray-200">{{ byLane[key].length }}</span>
                        </div>
                        <div class="flex max-h-[26rem] flex-col gap-2 overflow-y-auto px-2 pb-2">
                            <button v-for="wo in byLane[key]" :key="wo.work_order_id" type="button" @click="openWo(wo)"
                                class="group rounded-lg bg-white p-2.5 text-left shadow-sm ring-1 ring-gray-100 transition hover:shadow-md hover:ring-op">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-xs text-gray-500">{{ shortId(wo.work_order_id) }}</span>
                                    <StatusBadge :status="wo.priority" v-if="wo.priority" />
                                </div>
                                <div class="mt-1 truncate text-sm font-medium text-gray-800">{{ t(wo.type ?? 'Work order') }}</div>
                                <div class="mt-0.5 truncate text-xs text-gray-500">{{ customerOf(wo) ?? t('No customer') }}</div>
                                <div class="mt-1.5 flex items-center justify-between gap-2">
                                    <span class="truncate text-[11px] text-gray-400">{{ assigneeOf(wo) ? shortId(assigneeOf(wo)) : t('Unassigned') }}</span>
                                    <span v-if="wo.sla_due_at" class="shrink-0 text-[10px] text-gray-400">{{ dateFmt(wo.sla_due_at) }}</span>
                                </div>
                            </button>
                            <p v-if="!loading && !byLane[key].length" class="px-1 py-6 text-center text-xs text-gray-400">{{ t('Empty') }}</p>
                            <p v-if="loading" class="px-1 py-6 text-center text-xs text-gray-400">{{ t('Loading…') }}</p>
                        </div>
                    </div>
                </div>
            </Panel>

            <!-- Queue table -->
            <Panel title="Work order queue" subtitle="All work orders — select a row for detail">
                <DataTable :columns="columns" :rows="tableRows" row-key="work_order_id"
                    :loading="loading" empty="No work orders." @select="openWo">
                    <template #cell-work_order_id="{ value }">
                        <span class="font-mono text-xs text-gray-600">{{ shortId(value) }}</span>
                    </template>
                    <template #cell-type="{ value }">{{ value ? t(value) : '—' }}</template>
                    <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-assignee="{ value }">
                        <span v-if="value" class="font-mono text-xs text-gray-600">{{ shortId(value) }}</span>
                        <span v-else class="text-xs text-amber-600">{{ t('Unassigned') }}</span>
                    </template>
                </DataTable>
            </Panel>
        </div>

        <!-- WO detail drawer -->
        <Drawer v-model:open="open" title="Work order" width="max-w-2xl">
            <div v-if="detail" class="space-y-5">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <div class="font-mono text-xs text-gray-400">{{ detail.work_order_id }}</div>
                        <div class="text-base font-semibold text-gray-800">{{ t(detail.type ?? 'Work order') }}</div>
                    </div>
                    <StatusBadge :status="detail.status" />
                </div>

                <!-- Lifecycle -->
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-100">
                    <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Lifecycle') }}</div>
                    <StageTracker :stages="stages" />
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

                <!-- Assignment action -->
                <div v-if="canAssign" class="rounded-xl bg-white p-4 ring-1 ring-gray-100">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Assign') }}</div>
                    <p class="mb-2 text-xs text-gray-400">{{ t('Dispatch to a technician, contractor or team.') }}</p>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <input v-model="assignForm.assigned_technician_id" :placeholder="t('Technician ID')" class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm" />
                        <input v-model="assignForm.contractor_id" :placeholder="t('Contractor ID')" class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm" />
                        <input v-model="assignForm.team_id" :placeholder="t('Team ID')" class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm" />
                    </div>
                    <button type="button" :disabled="!assignFilled" @click="assign"
                        class="mt-2 rounded-lg bg-op px-3 py-1.5 text-sm font-medium text-white hover:bg-op-dark disabled:cursor-not-allowed disabled:opacity-40">
                        {{ t('Assign work order') }}
                    </button>
                </div>

                <!-- Finalization review -->
                <div v-if="canFinalize" class="rounded-xl bg-white p-4 ring-1 ring-cyan-100 ring-2">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-cyan-700">{{ t('Finalization review') }}</div>
                    <p class="mb-2 text-xs text-gray-500">{{ t('Run the finalization checklist and complete this work order.') }}</p>
                    <button type="button" @click="approveFinalization"
                        class="rounded-lg bg-cyan-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-cyan-700">
                        {{ t('Approve & complete') }}
                    </button>
                </div>

                <!-- Activity / assignment history -->
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-100">
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t('Activity & assignment history') }}</span>
                        <span v-if="detailLoading" class="text-xs text-gray-400">{{ t('Loading…') }}</span>
                    </div>
                    <Timeline :events="history" />
                </div>
            </div>
            <p v-else class="text-sm text-gray-400">{{ t('No work order selected.') }}</p>
        </Drawer>
    </AuthenticatedLayout>
</template>
