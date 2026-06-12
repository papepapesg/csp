<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();

// Rules Studio (FOUNDATION_DROOLS as config). Decision tables are DATA: edit the
// rules (when -> then), deploy a new version, and the engine evaluates them live.
// An operator can override any policy with no code change.
const tables = ref([]);
const current = ref(null);
const error = ref(null);
const notice = ref(null);

const ops = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'truthy', 'falsy', 'in'];

const blank = () => ({
    rule_set: '', name: '', operator_code: '', hit_policy: 'FIRST',
    rules: [{ when: [{ var: '', op: 'gt', value: 0 }], then: { } }],
    default_output: { eligible: true },
});

const test = ref({ facts: '{\n  "outstandingBalance": 100\n}', result: null });

async function load() {
    const { data } = await window.axios.get('/api/rules/decision-tables', { params: { size: 100 } });
    tables.value = data.items ?? [];
}
function open(t) {
    current.value = JSON.parse(JSON.stringify(t));
    // normalise then/default_output to editable JSON strings
    current.value.rules = (current.value.rules ?? []).map((r) => ({ when: r.when ?? [], thenJson: JSON.stringify(r.then ?? {}, null, 0) }));
    current.value.defaultJson = JSON.stringify(current.value.default_output ?? {}, null, 0);
    test.value.result = null;
}
function create() {
    const t = blank();
    t.rules = t.rules.map((r) => ({ when: r.when, thenJson: JSON.stringify(r.then) }));
    t.defaultJson = JSON.stringify(t.default_output);
    current.value = t;
}
function addRule() { current.value.rules.push({ when: [{ var: '', op: 'gt', value: 0 }], thenJson: '{"eligible": false, "reason": "X"}' }); }
function addCond(rule) { rule.when.push({ var: '', op: 'eq', value: '' }); }
function removeRule(i) { current.value.rules.splice(i, 1); }
function removeCond(rule, i) { rule.when.splice(i, 1); }

function serialise() {
    return {
        rule_set: current.value.rule_set,
        name: current.value.name,
        operator_code: current.value.operator_code || null,
        hit_policy: current.value.hit_policy,
        rules: current.value.rules.map((r) => ({
            when: r.when.map((c) => ({ var: c.var, op: c.op, value: coerce(c.value) })),
            then: JSON.parse(r.thenJson || '{}'),
        })),
        default_output: JSON.parse(current.value.defaultJson || '{}'),
    };
}
function coerce(v) {
    if (v === 'true') return true; if (v === 'false') return false;
    if (v !== '' && !isNaN(v)) return Number(v);
    return v;
}

async function save() {
    error.value = null; notice.value = null;
    try {
        const body = serialise();
        if (current.value.table_id) {
            await window.axios.put(`/api/rules/decision-tables/${current.value.table_id}`, { name: body.name, hit_policy: body.hit_policy, rules: body.rules, default_output: body.default_output });
            notice.value = t('Decision table updated.');
        } else {
            const { data } = await window.axios.post('/api/rules/decision-tables', body);
            current.value.table_id = data.table_id;
            notice.value = `Deployed ${data.rule_set} v${data.version}.`;
        }
        await load();
    } catch (e) { error.value = e.response?.data?.message ?? t('Save failed (check JSON)'); }
}

async function runTest() {
    error.value = null;
    try {
        const facts = JSON.parse(test.value.facts);
        const { data } = await window.axios.post(`/api/rules/${current.value.rule_set}/evaluate`, { facts });
        test.value.result = data.decision;
    } catch (e) { error.value = e.response?.data?.message ?? t('Evaluate failed'); }
}

onMounted(load);
</script>

<template>
    <Head :title="t('Rules Studio')" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ t('Rules Studio (FOUNDATION_DROOLS)') }}</h2>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-[100rem] px-4 sm:px-6 lg:px-8">
                <p class="mb-3 text-sm text-gray-500">{{ t('Decision tables are configuration. Edit the rules, deploy a new version, and the engine applies the new policy live — operators override per market with no code change.') }}</p>
                <p v-if="error" class="mb-3 rounded bg-red-50 p-2 text-sm text-red-600">{{ error }}</p>
                <p v-if="notice" class="mb-3 rounded bg-green-50 p-2 text-sm text-green-700">{{ notice }}</p>

                <div class="grid grid-cols-12 gap-4">
                    <!-- list -->
                    <div class="col-span-3 rounded-lg bg-white p-3 shadow-sm">
                        <div class="mb-2 flex items-center">
                            <h3 class="flex-1 text-xs font-semibold uppercase text-gray-500">{{ t('Decision tables') }}</h3>
                            <button class="rounded bg-gray-800 px-2 py-1 text-xs text-white" @click="create">{{ t('+ New') }}</button>
                        </div>
                        <ul class="space-y-1 text-sm">
                            <li v-for="tb in tables" :key="tb.table_id">
                                <button class="w-full rounded px-2 py-1 text-left hover:bg-gray-100" :class="current?.table_id === tb.table_id ? 'bg-gray-100 font-medium' : ''" @click="open(tb)">
                                    {{ tb.rule_set }} <span class="text-xs text-gray-400">v{{ tb.version }}{{ tb.operator_code ? ' · '+tb.operator_code : '' }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- editor -->
                    <div class="col-span-6 space-y-3">
                        <div v-if="current" class="rounded-lg bg-white p-4 shadow-sm">
                            <div class="grid grid-cols-2 gap-3">
                                <div><div class="label text-xs text-gray-500">{{ t('Rule set') }}</div><input v-model="current.rule_set" :disabled="!!current.table_id" class="w-full rounded border-gray-300 text-sm" /></div>
                                <div><div class="text-xs text-gray-500">{{ t('Name') }}</div><input v-model="current.name" class="w-full rounded border-gray-300 text-sm" /></div>
                                <div><div class="text-xs text-gray-500">{{ t('Operator (blank = global)') }}</div><input v-model="current.operator_code" :placeholder="t('WIK / WTZ…')" class="w-full rounded border-gray-300 text-sm" /></div>
                                <div><div class="text-xs text-gray-500">{{ t('Hit policy') }}</div><select v-model="current.hit_policy" class="w-full rounded border-gray-300 text-sm"><option>FIRST</option><option>COLLECT</option></select></div>
                            </div>

                            <h4 class="mt-4 text-xs font-semibold uppercase text-gray-500">{{ t('Rules (when → then)') }}</h4>
                            <div v-for="(rule, ri) in current.rules" :key="ri" class="mt-2 rounded border p-2">
                                <div class="mb-1 flex items-center text-xs text-gray-500"><span class="flex-1">{{ t('WHEN (all)') }}</span><button class="text-red-500" @click="removeRule(ri)">{{ t('remove rule') }}</button></div>
                                <div v-for="(c, ci) in rule.when" :key="ci" class="mb-1 flex gap-1">
                                    <input v-model="c.var" :placeholder="t('variable')" class="flex-1 rounded border-gray-300 text-xs" />
                                    <select v-model="c.op" class="rounded border-gray-300 text-xs"><option v-for="o in ops" :key="o">{{ o }}</option></select>
                                    <input v-model="c.value" :placeholder="t('value')" class="w-24 rounded border-gray-300 text-xs" />
                                    <button class="px-1 text-red-400" @click="removeCond(rule, ci)">×</button>
                                </div>
                                <button class="text-xs text-indigo-600" @click="addCond(rule)">{{ t('+ condition') }}</button>
                                <div class="mt-1 text-xs text-gray-500">{{ t('THEN (output JSON)') }}</div>
                                <input v-model="rule.thenJson" class="w-full rounded border-gray-300 font-mono text-xs" />
                            </div>
                            <button class="mt-2 text-sm text-indigo-600" @click="addRule">{{ t('+ rule') }}</button>

                            <div class="mt-3 text-xs text-gray-500">{{ t('Default output (no rule matched)') }}</div>
                            <input v-model="current.defaultJson" class="w-full rounded border-gray-300 font-mono text-xs" />

                            <button class="mt-3 w-full rounded bg-indigo-600 py-2 text-sm font-semibold text-white" @click="save">{{ current.table_id ? t('Save table') : t('Deploy new table') }}</button>
                        </div>
                        <p v-else class="text-sm text-gray-400">{{ t('Select or create a decision table.') }}</p>
                    </div>

                    <!-- test -->
                    <div class="col-span-3">
                        <div v-if="current" class="rounded-lg bg-white p-4 shadow-sm">
                            <h3 class="mb-2 text-xs font-semibold uppercase text-gray-500">{{ t('Test:') }} {{ current.rule_set }}</h3>
                            <div class="text-xs text-gray-500">{{ t('Facts (JSON)') }}</div>
                            <textarea v-model="test.facts" rows="5" class="w-full rounded border-gray-300 font-mono text-xs"></textarea>
                            <button class="mt-2 w-full rounded bg-gray-800 py-1.5 text-sm text-white" @click="runTest">{{ t('Evaluate') }}</button>
                            <div v-if="test.result" class="mt-2 rounded bg-gray-900 p-2 font-mono text-xs text-green-300">{{ JSON.stringify(test.result, null, 2) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
