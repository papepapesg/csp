<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';

// Dunning Program Studio (BIL-04). A dunning program is basically an escalation workflow:
// an ordered ladder of levels, each with a grace period and an action (warn / restrict /
// suspend / terminate). Programs are versioned — editing publishes a NEW version; published
// versions are immutable, so in-flight dunning episodes keep running under the policy they
// pinned at entry. Channels for the warning notice are NOT here — those live in NOT-01 routing.
const intents = ['WARNING_ONLY', 'RESTRICTION_ADD', 'SUSPEND_NP', 'TERMINATION'];
const intentLabel = { WARNING_ONLY: 'Warn', RESTRICTION_ADD: 'Restrict', SUSPEND_NP: 'Suspend', TERMINATION: 'Terminate' };
const intentColor = { WARNING_ONLY: 'bg-amber-100 text-amber-800', RESTRICTION_ADD: 'bg-orange-100 text-orange-800', SUSPEND_NP: 'bg-red-100 text-red-700', TERMINATION: 'bg-gray-800 text-white' };

const programs = ref([]);
const current = ref(null);          // the selected program (active version) being viewed/edited
const dirty = ref(false);
const error = ref(null);
const notice = ref(null);

// group by code -> versions
const grouped = computed(() => {
    const m = {};
    for (const p of programs.value) (m[p.code] ??= []).push(p);
    for (const code in m) m[code].sort((a, b) => b.version - a.version);
    return m;
});
const totalCycle = computed(() => (current.value?.level_definitions ?? []).reduce((s, l) => s + (Number(l.grace_period_days) || 0), 0));

async function load() {
    const { data } = await window.axios.get('/api/dunning-programs');
    programs.value = data.items ?? [];
}
function open(p) { current.value = JSON.parse(JSON.stringify(p)); dirty.value = false; notice.value = error.value = null; }
function isActive(p) { return !p.retired_at; }

function blankProgram() {
    current.value = {
        code: '', operator_code: '', billing_mode: 'POSTPAID', description: '',
        pre_termination_review_required: true, version: null, _new: true,
        level_definitions: [
            { level: 1, name: 'WARNING', grace_period_days: 7, action_workflow_intent: 'WARNING_ONLY', action_payload: {} },
            { level: 2, name: 'RESTRICTED', grace_period_days: 7, action_workflow_intent: 'RESTRICTION_ADD', action_payload: { restriction_codes: [] } },
            { level: 3, name: 'SUSPENDED', grace_period_days: 14, action_workflow_intent: 'SUSPEND_NP', action_payload: { reason_code: 'DUNNING_GRACE_EXPIRED' } },
            { level: 4, name: 'TERMINATED', grace_period_days: 30, action_workflow_intent: 'TERMINATION', action_payload: { reason_code: 'DUNNING_TERMINATION', equipment_disposition: 'PENDING_COLLECTION' } },
        ],
    };
    dirty.value = true;
}
function addLevel() {
    const levels = current.value.level_definitions;
    levels.push({ level: levels.length + 1, name: 'LEVEL_' + (levels.length + 1), grace_period_days: 7, action_workflow_intent: 'WARNING_ONLY', action_payload: {} });
    dirty.value = true;
}
function removeLevel(i) { current.value.level_definitions.splice(i, 1); renumber(); dirty.value = true; }
function renumber() { current.value.level_definitions.forEach((l, i) => (l.level = i + 1)); }
function codesFor(level) { return (level.action_payload?.restriction_codes ?? []).join(', '); }
function setCodes(level, v) { level.action_payload.restriction_codes = v.split(',').map(s => s.trim()).filter(Boolean); dirty.value = true; }

async function publish() {
    error.value = notice.value = null;
    try {
        renumber();
        const body = {
            description: current.value.description,
            level_definitions: current.value.level_definitions,
            pre_termination_review_required: current.value.pre_termination_review_required,
        };
        if (current.value._new) {
            await window.axios.post('/api/dunning-programs', {
                code: current.value.code, operator_code: current.value.operator_code || undefined,
                billing_mode: current.value.billing_mode, ...body,
            });
            notice.value = 'Program created (v1)';
        } else {
            // Editing an existing program ALWAYS publishes a new version (immutability).
            const { data } = await window.axios.post(`/api/dunning-programs/${current.value.code}/new-version`, body);
            notice.value = `Published v${data.version} (previous version retired)`;
        }
        dirty.value = false;
        await load();
    } catch (e) { error.value = e.response?.data?.message ?? 'Publish failed'; }
}

onMounted(load);
</script>

<template>
    <Head title="Dunning Studio" />
    <AuthenticatedLayout>
        <template #header><h2 class="font-semibold text-xl text-gray-800">Dunning Program Studio</h2></template>

        <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div v-if="error" class="mb-3 p-2 bg-red-100 text-red-700 rounded text-sm">{{ error }}</div>
            <div v-if="notice" class="mb-3 p-2 bg-green-100 text-green-700 rounded text-sm">{{ notice }}</div>

            <div class="grid grid-cols-12 gap-4">
                <!-- program list -->
                <div class="col-span-3 bg-white rounded shadow p-3">
                    <button @click="blankProgram" class="w-full mb-3 px-3 py-1 bg-indigo-600 text-white rounded text-sm">+ New program</button>
                    <div v-for="(versions, code) in grouped" :key="code" class="mb-3">
                        <div class="text-xs font-semibold text-gray-500">{{ code }}</div>
                        <button v-for="p in versions" :key="p.id" @click="open(p)"
                            class="block w-full text-left px-2 py-1 rounded text-sm hover:bg-gray-100"
                            :class="current && current.code === p.code && current.version === p.version ? 'bg-indigo-50' : ''">
                            <span class="text-xs">{{ p.operator_code }} · {{ p.billing_mode }}</span>
                            <span class="text-xs text-gray-400">v{{ p.version }}</span>
                            <span class="ml-1" :class="isActive(p) ? 'text-green-600' : 'text-gray-300'">●</span>
                        </button>
                    </div>
                </div>

                <!-- ladder editor -->
                <div v-if="current" class="col-span-9 bg-white rounded shadow p-4 space-y-4">
                    <div class="flex flex-wrap gap-2 items-center">
                        <input v-model="current.code" :disabled="!current._new" placeholder="program_code" class="border rounded px-2 py-1 text-sm font-mono disabled:bg-gray-100" />
                        <select v-model="current.billing_mode" :disabled="!current._new" @change="dirty = true" class="border rounded px-2 py-1 text-sm disabled:bg-gray-100">
                            <option>POSTPAID</option><option>PREPAID</option><option>PREPAYMENT</option>
                        </select>
                        <input v-if="current._new" v-model="current.operator_code" placeholder="operator (blank = current)" class="border rounded px-2 py-1 text-sm" />
                        <span v-if="!current._new" class="text-xs text-gray-500">editing v{{ current.version }} — publishing creates v{{ current.version + 1 }}</span>
                        <label class="text-xs flex items-center gap-1 ml-auto">
                            <input type="checkbox" v-model="current.pre_termination_review_required" @change="dirty = true" /> require review before terminate
                        </label>
                    </div>
                    <input v-model="current.description" @input="dirty = true" placeholder="Description" class="border rounded px-2 py-1 text-sm w-full" />

                    <!-- the escalation ladder -->
                    <div class="space-y-2">
                        <div v-for="(level, i) in current.level_definitions" :key="i" class="flex gap-2 items-start border rounded p-2">
                            <div class="w-6 text-center font-bold text-gray-400 pt-1">{{ level.level }}</div>
                            <div class="flex-1 grid grid-cols-12 gap-2">
                                <input v-model="level.name" @input="dirty = true" placeholder="NAME" class="col-span-3 border rounded px-2 py-1 text-sm" />
                                <div class="col-span-3 flex items-center gap-1">
                                    <input type="number" min="0" v-model.number="level.grace_period_days" @input="dirty = true" class="border rounded px-2 py-1 text-sm w-16" />
                                    <span class="text-xs text-gray-400">days grace</span>
                                </div>
                                <select v-model="level.action_workflow_intent" @change="dirty = true" class="col-span-3 border rounded px-2 py-1 text-sm"
                                    :class="intentColor[level.action_workflow_intent]">
                                    <option v-for="x in intents" :key="x" :value="x">{{ intentLabel[x] }}</option>
                                </select>
                                <button @click="removeLevel(i)" class="col-span-3 text-xs text-red-500 hover:underline text-right pr-1">remove</button>

                                <!-- action payload, per intent -->
                                <input v-if="level.action_workflow_intent === 'RESTRICTION_ADD'" :value="codesFor(level)" @input="setCodes(level, $event.target.value)"
                                    placeholder="restriction codes (comma-separated, from SUB-LM-01 catalog)" class="col-span-12 border rounded px-2 py-1 text-xs font-mono" />
                                <input v-if="level.action_workflow_intent === 'SUSPEND_NP'" v-model="level.action_payload.reason_code" @input="dirty = true"
                                    placeholder="suspend reason_code" class="col-span-6 border rounded px-2 py-1 text-xs font-mono" />
                                <template v-if="level.action_workflow_intent === 'TERMINATION'">
                                    <input v-model="level.action_payload.reason_code" @input="dirty = true" placeholder="termination reason_code" class="col-span-6 border rounded px-2 py-1 text-xs font-mono" />
                                    <input v-model="level.action_payload.equipment_disposition" @input="dirty = true" placeholder="equipment_disposition" class="col-span-6 border rounded px-2 py-1 text-xs font-mono" />
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button @click="addLevel" class="px-3 py-1 border rounded text-sm">+ Add level</button>
                        <span class="text-xs text-gray-500">Total cycle: {{ totalCycle }} days</span>
                        <button @click="publish" :disabled="!dirty" class="ml-auto px-3 py-1 bg-green-600 text-white rounded text-sm disabled:opacity-40">
                            {{ current._new ? 'Create program' : 'Publish new version' }}
                        </button>
                    </div>
                    <p class="text-xs text-gray-400">Channels for the dunning notice are not configured here — they live in NOT-01 routing rules (per operator + customer preference). This studio owns the escalation policy only.</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
