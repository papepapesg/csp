<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import Drawer from '@/Components/Bss/Drawer.vue';
import Timeline from '@/Components/Bss/Timeline.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

// FE-APP-01 §14 Workforce backoffice surface over the EM-02 (Workforce) + RLM-CFG-01 (Catalog
// tech-coverage) backends. A tabbed registry console: contractors (with assignment drawer),
// staff/agents, teams (derived from membership), and a region-availability/capacity probe.
// This page holds no business state — every panel reads/writes the owning API and degrades
// gracefully per-tile when a module is down or an endpoint is absent.
const { t, dateFmt } = useI18n();

// --- shape normalisation (DD_API-00: paginated {items,…} or {items:[…]} item envelopes) ---
const list = (d) => (Array.isArray(d) ? d : (d?.items ?? d?.data ?? []));

const notice = ref(null);
const error = ref(null);
const flash = (m) => { notice.value = m; setTimeout(() => (notice.value = null), 3500); };
const fail = (e) => { error.value = e?.response?.data?.message ?? t('Request failed'); setTimeout(() => (error.value = null), 6000); };

const tab = ref('contractors');
const tabs = [
    ['contractors', 'Contractors'],
    ['staff', 'Staff & agents'],
    ['teams', 'Teams'],
    ['availability', 'Availability'],
];

// --- registries ---
const contractors = ref([]);
const techContractors = ref([]); // RLM-CFG-01 tech contractors (skill/coverage view)
const staff = ref([]);
const skills = ref([]); // RLM-CFG-01 skill catalog
const regions = ref([]); // tech regions (assignment targets)
const loading = ref({ contractors: false, staff: false, teams: false });

async function loadContractors() {
    loading.value.contractors = true;
    try {
        const { data } = await window.axios.get('/api/contractors');
        contractors.value = list(data);
    } catch (e) { contractors.value = []; } finally { loading.value.contractors = false; }
    // RLM-CFG-01 catalog tech-coverage view (skills/regions) — independent, best-effort.
    try { techContractors.value = list((await window.axios.get('/api/tech-contractors')).data); } catch (e) { techContractors.value = []; }
    try { skills.value = list((await window.axios.get('/api/tech-contractor-skills')).data); } catch (e) { skills.value = []; }
    try { regions.value = list((await window.axios.get('/api/tech-regions')).data); } catch (e) { regions.value = []; }
}

async function loadStaff() {
    loading.value.staff = true;
    try {
        const { data } = await window.axios.get('/api/staff');
        staff.value = list(data);
    } catch (e) { staff.value = []; } finally { loading.value.staff = false; }
}

// Teams have no list endpoint (EM-02 exposes only POST contractors/{id}/teams). We derive the
// team roster from staff membership so the tab is still useful; flagged as a known gap.
const teams = computed(() => {
    const byId = new Map();
    for (const s of staff.value) {
        const id = s.team_id;
        if (!id) continue;
        if (!byId.has(id)) byId.set(id, { team_id: id, contractor_id: s.contractor_id ?? null, members: [] });
        byId.get(id).members.push(s);
    }
    return [...byId.values()].map((row) => ({ ...row, member_count: row.members.length }));
});

// --- summary stat cards ---
const activeContractors = computed(() => contractors.value.filter((c) => (c.status ?? 'ACTIVE') !== 'INACTIVE').length);
const stats = computed(() => [
    { key: 'contractors', label: 'Active contractors', value: activeContractors.value, sub: `${contractors.value.length} ${t('total')}`, tone: 'indigo' },
    { key: 'staff', label: 'Staff & agents', value: staff.value.length, sub: t('Technicians, leads, supervisors'), tone: 'cyan' },
    { key: 'teams', label: 'Teams', value: teams.value.length, sub: t('Derived from membership'), tone: 'emerald' },
    { key: 'skills', label: 'Skills catalog', value: skills.value.length, sub: `${regions.value.length} ${t('tech regions')}`, tone: 'amber' },
]);

// --- contractor detail drawer ---
const drawer = ref(false);
const selected = ref(null);
const assign = ref({ regionId: '', skills: '' });

function openContractor(row) {
    selected.value = row;
    // Cross-reference the catalog tech-coverage twin (matched by code) for richer detail.
    const twin = techContractors.value.find((tc) => tc.code === row.code);
    selected.value = { ...row, _twin: twin ?? null };
    assign.value = { regionId: regions.value[0]?.tech_region_id ?? '', skills: (row.skills ?? []).join(', ') };
    drawer.value = true;
}

// Synthesise a lightweight history Timeline from whatever timestamps the row carries — there is
// no per-contractor availability/assignment history endpoint in EM-02 today.
const history = computed(() => {
    const c = selected.value;
    if (!c) return [];
    const ev = [];
    if (c.created_at) ev.push({ title: t('Contractor registered'), subtitle: c.code, at: dateFmt(c.created_at), tone: 'green' });
    if (c.updated_at && c.updated_at !== c.created_at) ev.push({ title: t('Registry updated'), subtitle: c.status ?? '', at: dateFmt(c.updated_at), tone: 'indigo' });
    const tw = c._twin;
    if (tw?.created_at) ev.push({ title: t('Tech-coverage profile created'), subtitle: t('RLM-CFG-01'), at: dateFmt(tw.created_at), tone: 'blue' });
    return ev;
});

// Skill/region assignment — wired to RLM-CFG-01 POST /tech-regions/{id}/contractors. Requires
// the catalog tech-contractor twin (its contractor_id is the assignment subject).
const canAssign = computed(() => !!selected.value?._twin && regions.value.length > 0);
async function assignRegion() {
    const tw = selected.value?._twin;
    if (!tw || !assign.value.regionId) return;
    const sk = assign.value.skills.split(',').map((s) => s.trim()).filter(Boolean);
    try {
        await window.axios.post(`/api/tech-regions/${assign.value.regionId}/contractors`, {
            contractor_id: tw.contractor_id,
            skills: sk,
        });
        flash(t('Region assignment saved'));
        await loadContractors();
    } catch (e) { fail(e); }
}

// --- availability / capacity probe (EM-02 §5.1 POST /api/contractor-availability) ---
const probe = ref({ techRegionId: '', serviceScope: 'INSTALL', requiredSkills: '', windowStart: '', windowEnd: '' });
const probeResult = ref(null);
const probing = ref(false);
const probeRan = ref(false);

function initProbeWindow() {
    if (probe.value.windowStart) return;
    const now = new Date();
    const end = new Date(now.getTime() + 7 * 24 * 3600 * 1000);
    const iso = (d) => d.toISOString().slice(0, 16);
    probe.value.windowStart = iso(now);
    probe.value.windowEnd = iso(end);
    probe.value.techRegionId = regions.value[0]?.tech_region_id ?? '';
}

async function runProbe() {
    if (!probe.value.techRegionId) { fail({ response: { data: { message: t('Select a tech region first.') } } }); return; }
    probing.value = true; probeRan.value = true;
    try {
        const { data } = await window.axios.post('/api/contractor-availability', {
            techRegionId: probe.value.techRegionId,
            serviceScope: probe.value.serviceScope,
            requiredSkills: probe.value.requiredSkills.split(',').map((s) => s.trim()).filter(Boolean),
            windowStart: new Date(probe.value.windowStart).toISOString(),
            windowEnd: new Date(probe.value.windowEnd).toISOString(),
        });
        probeResult.value = data;
    } catch (e) { probeResult.value = null; fail(e); } finally { probing.value = false; }
}

const probeRows = computed(() => list(probeResult.value?.candidates ?? probeResult.value?.results ?? probeResult.value?.contractors ?? []));

// --- columns ---
const contractorCols = [
    { key: 'name', label: 'Name' },
    { key: 'code', label: 'Code', class: 'font-mono text-xs text-gray-500' },
    { key: 'type', label: 'Type' },
    { key: 'status', label: 'Status' },
    { key: 'skills', label: 'Skills / regions' },
];
const staffCols = [
    { key: 'name', label: 'Name' },
    { key: 'role', label: 'Role' },
    { key: 'msisdn', label: 'MSISDN', class: 'font-mono text-xs text-gray-500' },
    { key: 'contractor_id', label: 'Contractor', class: 'font-mono text-xs text-gray-400' },
    { key: 'team_id', label: 'Team', class: 'font-mono text-xs text-gray-400' },
    { key: 'skills', label: 'Skills' },
];
const teamCols = [
    { key: 'team_id', label: 'Team', class: 'font-mono text-xs text-gray-500' },
    { key: 'contractor_id', label: 'Contractor', class: 'font-mono text-xs text-gray-400' },
    { key: 'member_count', label: 'Members', align: 'right' },
];

function selectTab(key) {
    tab.value = key;
    if (key === 'availability') initProbeWindow();
}

onMounted(async () => {
    await Promise.allSettled([loadContractors(), loadStaff()]);
});
</script>

<template>
    <Head :title="t('Workforce')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="Workforce" :crumbs="[{ label: 'Commerce' }, { label: 'Workforce' }]" />
        </template>

        <div class="mx-auto max-w-7xl space-y-5">
            <p v-if="notice" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 ring-1 ring-emerald-100">{{ notice }}</p>
            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</p>

            <!-- Summary -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard v-for="s in stats" :key="s.key" :label="s.label" :value="s.value" :sub="s.sub" :tone="s.tone"
                    :loading="loading.contractors || loading.staff" @select="selectTab(s.key === 'skills' ? 'contractors' : s.key === 'staff' ? 'staff' : s.key)" />
            </div>

            <!-- Tabs -->
            <div class="flex flex-wrap gap-2">
                <button v-for="[key, label] in tabs" :key="key" @click="selectTab(key)"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 transition"
                    :class="tab === key ? 'bg-op text-white ring-op' : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50'">
                    {{ t(label) }}
                </button>
            </div>

            <!-- CONTRACTORS -->
            <Panel v-if="tab === 'contractors'" title="Contractor registry" subtitle="EM-02 contractors with RLM-CFG-01 skill & region coverage">
                <template #actions>
                    <span class="text-xs text-gray-400">{{ contractors.length }} {{ t('contractors') }}</span>
                </template>
                <DataTable :columns="contractorCols" :rows="contractors" row-key="contractor_id"
                    :loading="loading.contractors" empty="No contractors registered." @select="openContractor">
                    <template #cell-status="{ value }"><StatusBadge :status="value ?? 'ACTIVE'" /></template>
                    <template #cell-type="{ value }">
                        <span class="text-xs text-gray-600">{{ value ? t(value) : '—' }}</span>
                    </template>
                    <template #cell-skills="{ row }">
                        <div class="flex flex-wrap gap-1">
                            <span v-for="sk in (row.skills ?? [])" :key="sk" class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600">{{ sk }}</span>
                            <span v-if="!(row.skills ?? []).length" class="text-xs text-gray-300">—</span>
                        </div>
                    </template>
                    <template #row-actions="{ row }">
                        <button class="text-xs font-medium text-op hover:underline" @click="openContractor(row)">{{ t('Manage') }}</button>
                    </template>
                </DataTable>
            </Panel>

            <!-- STAFF -->
            <Panel v-if="tab === 'staff'" title="Staff & agent registry" subtitle="EM-02 staff members and their contractor / team mapping">
                <template #actions>
                    <span class="text-xs text-gray-400">{{ staff.length }} {{ t('staff') }}</span>
                </template>
                <DataTable :columns="staffCols" :rows="staff" row-key="staff_id" :loading="loading.staff" empty="No staff registered.">
                    <template #cell-role="{ value }">
                        <StatusBadge v-if="value" :status="value" :map="{ TECHNICIAN: 'bg-blue-100 text-blue-700 ring-blue-600/20', TEAM_LEAD: 'bg-op-soft text-op ring-op/20', SUPERVISOR: 'bg-emerald-100 text-emerald-700 ring-emerald-600/20' }" />
                        <span v-else class="text-xs text-gray-300">—</span>
                    </template>
                    <template #cell-skills="{ row }">
                        <div class="flex flex-wrap gap-1">
                            <span v-for="sk in (row.skills ?? [])" :key="sk" class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600">{{ sk }}</span>
                            <span v-if="!(row.skills ?? []).length" class="text-xs text-gray-300">—</span>
                        </div>
                    </template>
                </DataTable>
            </Panel>

            <!-- TEAMS -->
            <Panel v-if="tab === 'teams'" title="Teams" subtitle="Derived from staff membership (EM-02 exposes no team list endpoint)">
                <DataTable :columns="teamCols" :rows="teams" row-key="team_id" empty="No team memberships found.">
                    <template #cell-member_count="{ value }">
                        <span class="inline-flex items-center rounded-full bg-op-soft px-2 py-0.5 text-xs font-medium text-op">{{ value }}</span>
                    </template>
                </DataTable>
                <div v-for="team in teams" :key="team.team_id" class="mt-3 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-100">
                    <div class="mb-1 font-mono text-xs text-gray-500">{{ team.team_id }}</div>
                    <div class="flex flex-wrap gap-1.5">
                        <span v-for="m in team.members" :key="m.staff_id" class="rounded-full bg-white px-2 py-0.5 text-xs text-gray-700 ring-1 ring-gray-200">
                            {{ m.name }}<span v-if="m.role" class="text-gray-400"> · {{ t(m.role) }}</span>
                        </span>
                    </div>
                </div>
            </Panel>

            <!-- AVAILABILITY -->
            <Panel v-if="tab === 'availability'" title="Capacity availability probe" subtitle="EM-02 §5.1 — ranked contractors with capacity for a region, scope, skills & window">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                    <label class="text-xs text-gray-500">{{ t('Tech region') }}
                        <select v-model="probe.techRegionId" class="mt-1 w-full rounded border-gray-200 text-sm">
                            <option value="" disabled>{{ t('Select region') }}</option>
                            <option v-for="r in regions" :key="r.tech_region_id" :value="r.tech_region_id">{{ r.name ?? r.code ?? r.tech_region_id }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-gray-500">{{ t('Service scope') }}
                        <input v-model="probe.serviceScope" class="mt-1 w-full rounded border-gray-200 text-sm" />
                    </label>
                    <label class="text-xs text-gray-500">{{ t('Required skills') }}
                        <input v-model="probe.requiredSkills" :placeholder="t('comma,separated')" class="mt-1 w-full rounded border-gray-200 text-sm" />
                    </label>
                    <label class="text-xs text-gray-500">{{ t('Window start') }}
                        <input v-model="probe.windowStart" type="datetime-local" class="mt-1 w-full rounded border-gray-200 text-sm" />
                    </label>
                    <label class="text-xs text-gray-500">{{ t('Window end') }}
                        <input v-model="probe.windowEnd" type="datetime-local" class="mt-1 w-full rounded border-gray-200 text-sm" />
                    </label>
                </div>
                <div class="mt-3">
                    <button @click="runProbe" :disabled="probing"
                        class="rounded-lg bg-op px-4 py-1.5 text-sm font-medium text-white hover:bg-op-soft0 disabled:opacity-50">
                        {{ probing ? t('Evaluating…') : t('Evaluate capacity') }}
                    </button>
                    <span v-if="!regions.length" class="ml-3 text-xs text-amber-600">{{ t('No tech regions available — region catalog not reachable.') }}</span>
                </div>

                <div v-if="probeRan" class="mt-4">
                    <div class="mb-2 flex flex-wrap gap-3 text-xs text-gray-500">
                        <span v-if="probeResult?.evaluatedAt">{{ t('Evaluated') }}: {{ dateFmt(probeResult.evaluatedAt) }}</span>
                        <span>{{ probeRows.length }} {{ t('candidate(s) with capacity') }}</span>
                    </div>
                    <div v-if="probeRows.length" class="space-y-2">
                        <div v-for="(c, i) in probeRows" :key="c.contractorId ?? i" class="flex items-center justify-between rounded-lg bg-gray-50 p-3 ring-1 ring-gray-100">
                            <div>
                                <div class="text-sm font-medium text-gray-800">{{ c.contractorId ?? c.contractor_id ?? '—' }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ c.coverageRole ?? c.coverage_role ?? t('contractor') }}
                                    <span v-if="(c.matchedSkills ?? []).length"> · {{ (c.matchedSkills ?? []).join(', ') }}</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-lg font-semibold text-emerald-600">{{ c.slot?.remainingCapacity ?? c.slot?.remaining_capacity ?? c.remainingCapacity ?? '—' }}</div>
                                <div class="text-[10px] uppercase tracking-wide text-gray-400">{{ t('capacity') }}</div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="rounded-lg bg-gray-50 px-3 py-6 text-center text-sm text-gray-400">{{ t('No contractors with available capacity for these criteria.') }}</p>
                </div>
                <p v-else class="mt-4 text-sm text-gray-400">{{ t('Set the criteria above and evaluate to see ranked contractor capacity.') }}</p>
            </Panel>
        </div>

        <!-- Contractor detail / assignment drawer -->
        <Drawer v-model:open="drawer" :title="selected?.name ?? t('Contractor')" width="max-w-xl">
            <div v-if="selected" class="space-y-5">
                <div class="rounded-lg bg-white p-4 ring-1 ring-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="font-mono text-xs text-gray-400">{{ selected.code }}</div>
                            <div class="text-base font-semibold text-gray-800">{{ selected.name }}</div>
                        </div>
                        <StatusBadge :status="selected.status ?? 'ACTIVE'" />
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <div><dt class="text-xs text-gray-400">{{ t('Type') }}</dt><dd>{{ selected.type ? t(selected.type) : '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-400">{{ t('Tech-coverage profile') }}</dt><dd>{{ selected._twin ? t('Linked') : t('Not linked') }}</dd></div>
                    </dl>
                    <div class="mt-3">
                        <div class="mb-1 text-xs text-gray-400">{{ t('Skills') }}</div>
                        <div class="flex flex-wrap gap-1">
                            <span v-for="sk in (selected.skills ?? [])" :key="sk" class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600">{{ sk }}</span>
                            <span v-if="!(selected.skills ?? []).length" class="text-xs text-gray-300">—</span>
                        </div>
                    </div>
                </div>

                <!-- Region / skill assignment (RLM-CFG-01) -->
                <Panel title="Assign region & skills" subtitle="RLM-CFG-01 tech-region coverage">
                    <div v-if="canAssign" class="space-y-2">
                        <label class="block text-xs text-gray-500">{{ t('Tech region') }}
                            <select v-model="assign.regionId" class="mt-1 w-full rounded border-gray-200 text-sm">
                                <option v-for="r in regions" :key="r.tech_region_id" :value="r.tech_region_id">{{ r.name ?? r.code ?? r.tech_region_id }}</option>
                            </select>
                        </label>
                        <label class="block text-xs text-gray-500">{{ t('Skills (comma separated)') }}
                            <input v-model="assign.skills" class="mt-1 w-full rounded border-gray-200 text-sm" :placeholder="t('e.g. FIBRE_INSTALL, CPE_SWAP')" />
                        </label>
                        <button @click="assignRegion" class="rounded-lg bg-op px-3 py-1.5 text-sm font-medium text-white hover:bg-op-soft0">{{ t('Save assignment') }}</button>
                        <p v-if="skills.length" class="text-[11px] text-gray-400">{{ t('Known skill codes') }}: {{ skills.map((s) => s.code).join(', ') }}</p>
                    </div>
                    <p v-else class="text-sm text-gray-400">
                        {{ t('Assignment unavailable: this contractor has no linked RLM-CFG-01 tech-coverage profile, or no tech regions are reachable.') }}
                    </p>
                </Panel>

                <!-- History -->
                <Panel title="History" subtitle="Registration & coverage events">
                    <Timeline :events="history" />
                </Panel>
            </div>
        </Drawer>
    </AuthenticatedLayout>
</template>
