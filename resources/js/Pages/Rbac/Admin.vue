<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/Bss/PageHeader.vue';
import Panel from '@/Components/Bss/Panel.vue';
import StatCard from '@/Components/Bss/StatCard.vue';
import StatusBadge from '@/Components/Bss/StatusBadge.vue';
import DataTable from '@/Components/Bss/DataTable.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();

// RBAC Admin portal (EM-CFG-03). The role/permission catalog is runtime DATA, like
// every other studio: edit the role->permission matrix, create roles/permissions,
// assign business roles to users, and review the immutable change audit (§8.8).
// Authentication itself is the swappable foundation seam (local Sanctum today;
// Keycloak/OIDC — which federates LDAP/AD — per FOUNDATION_AUTH): this portal only
// manages the *authorization* catalog, which survives an auth-backend swap intact.
const tab = ref('matrix'); // matrix | users | audit

const roles = ref([]);
const permissions = ref([]);
const currentRole = ref(null);
const newRole = ref('');
const newPermission = ref('');

const users = ref([]);
const userQuery = ref('');
const selectedUser = ref(null);
const effective = ref(null);

const audit = ref([]);
const error = ref(null);
const notice = ref(null);
const flash = (msg) => { notice.value = msg; setTimeout(() => (notice.value = null), 4000); };

const grantSet = computed(() => new Set(currentRole.value?.permissions ?? []));
const permissionGroups = computed(() => {
    const groups = {};
    for (const p of permissions.value) (groups[p.split('.')[0]] ??= []).push(p);
    return groups;
});

async function load() {
    const r = await window.axios.get('/api/rbac/roles');
    roles.value = r.data.items ?? [];
    const p = await window.axios.get('/api/rbac/permissions');
    permissions.value = p.data.items ?? [];
    if (currentRole.value) currentRole.value = roles.value.find((x) => x.code === currentRole.value.code) ?? null;
}
function openRole(role) { currentRole.value = JSON.parse(JSON.stringify(role)); notice.value = error.value = null; }
function toggle(permission) {
    const set = new Set(currentRole.value.permissions);
    set.has(permission) ? set.delete(permission) : set.add(permission);
    currentRole.value.permissions = [...set];
}
async function saveMatrix() {
    try {
        await window.axios.put(`/api/rbac/roles/${currentRole.value.code}/permissions`, { permissions: currentRole.value.permissions });
        flash(t('Saved :code', { code: currentRole.value.code })); await load();
    } catch (e) { error.value = e.response?.data?.message ?? t('Save failed'); }
}
async function createRole() {
    if (!newRole.value) return;
    await window.axios.post('/api/rbac/roles', { code: newRole.value.toUpperCase(), permissions: [] });
    newRole.value = ''; await load();
}
async function createPermission() {
    if (!newPermission.value) return;
    await window.axios.post('/api/rbac/permissions', { code: newPermission.value.toLowerCase() });
    newPermission.value = ''; await load();
}

async function searchUsers() {
    const { data } = await window.axios.get('/api/rbac/users', { params: { q: userQuery.value, size: 20 } });
    users.value = data.items ?? data.content ?? [];
}
async function openUser(u) {
    selectedUser.value = JSON.parse(JSON.stringify(u));
    const { data } = await window.axios.get(`/api/rbac/users/${u.uid}/effective-access`);
    effective.value = data;
}
function toggleUserRole(code) {
    const set = new Set(selectedUser.value.roles);
    set.has(code) ? set.delete(code) : set.add(code);
    selectedUser.value.roles = [...set];
}
async function saveUserRoles() {
    try {
        await window.axios.post(`/api/rbac/users/${selectedUser.value.uid}/roles`, { roles: selectedUser.value.roles });
        flash(t('Roles updated for :name', { name: selectedUser.value.name }));
        await openUser(selectedUser.value); await searchUsers();
    } catch (e) { error.value = e.response?.data?.message ?? t('Save failed'); }
}

async function loadAudit() {
    const { data } = await window.axios.get('/api/rbac/audit', { params: { size: 50 } });
    audit.value = data.items ?? data.content ?? [];
}

const auditColumns = [
    { key: 'created_at', label: 'When' },
    { key: 'change_type', label: 'Change' },
    { key: 'target', label: 'Target' },
    { key: 'actor_user_id', label: 'Actor' },
];

onMounted(() => { load(); searchUsers(); loadAudit(); });
</script>

<template>
    <Head :title="t('RBAC Admin')" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader :title="t('RBAC & users')" :crumbs="[{ label: 'Settings' }, { label: 'Access control' }]" />
        </template>

        <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <div class="grid grid-cols-3 gap-4">
                <StatCard :label="t('Roles')" :value="roles.length" tone="indigo" />
                <StatCard :label="t('Permissions')" :value="permissions.length" tone="cyan" />
                <StatCard :label="t('Users')" :value="users.length" tone="emerald" />
            </div>

            <div class="inline-flex gap-1 rounded-xl bg-gray-100 p-1">
                <button @click="tab = 'matrix'" class="rounded-lg px-3 py-1.5 text-sm font-medium transition" :class="tab === 'matrix' ? 'bg-white text-op shadow-sm' : 'text-gray-500 hover:text-gray-700'">{{ t('Role ⇄ permission matrix') }}</button>
                <button @click="tab = 'users'; searchUsers()" class="rounded-lg px-3 py-1.5 text-sm font-medium transition" :class="tab === 'users' ? 'bg-white text-op shadow-sm' : 'text-gray-500 hover:text-gray-700'">{{ t('User assignments') }}</button>
                <button @click="tab = 'audit'; loadAudit()" class="rounded-lg px-3 py-1.5 text-sm font-medium transition" :class="tab === 'audit' ? 'bg-white text-op shadow-sm' : 'text-gray-500 hover:text-gray-700'">{{ t('Change audit') }}</button>
            </div>

            <div v-if="error" class="rounded-lg bg-red-50 p-2 text-sm text-red-700 ring-1 ring-red-100">{{ error }}</div>
            <div v-if="notice" class="rounded-lg bg-emerald-50 p-2 text-sm text-emerald-700 ring-1 ring-emerald-100">{{ notice }}</div>

            <!-- Matrix -->
            <div v-if="tab === 'matrix'" class="grid grid-cols-12 gap-5">
                <div class="col-span-12 lg:col-span-3">
                    <Panel :title="t('Roles')">
                        <div class="mb-3 flex gap-1">
                            <input v-model="newRole" placeholder="NEW_ROLE" class="w-full rounded-md border-gray-300 text-sm" />
                            <button @click="createRole" class="rounded-md bg-op px-2 text-sm text-white">+</button>
                        </div>
                        <button v-for="r in roles" :key="r.code" @click="openRole(r)"
                            class="mb-0.5 block w-full rounded-lg px-2 py-1.5 text-left text-sm hover:bg-gray-50"
                            :class="currentRole && currentRole.code === r.code ? 'bg-op-soft font-semibold text-op' : ''">
                            {{ r.code }} <span class="text-xs text-gray-400">({{ r.permissions.length }})</span>
                        </button>
                    </Panel>
                </div>

                <div class="col-span-12 lg:col-span-9">
                    <Panel v-if="currentRole" :title="currentRole.code" :subtitle="t('Toggle the permissions this role grants')">
                        <template #actions>
                            <input v-model="newPermission" placeholder="module.action" class="rounded-md border-gray-300 text-sm" />
                            <button @click="createPermission" class="rounded-md bg-gray-100 px-2 py-1.5 text-sm hover:bg-gray-200">{{ t('+ permission') }}</button>
                            <button @click="saveMatrix" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ t('Save matrix') }}</button>
                        </template>
                        <div v-for="(perms, group) in permissionGroups" :key="group" class="mb-4">
                            <div class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ group }}</div>
                            <div class="flex flex-wrap gap-1.5">
                                <button v-for="p in perms" :key="p" @click="toggle(p)"
                                    class="rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition"
                                    :class="grantSet.has(p) ? 'bg-emerald-100 text-emerald-700 ring-emerald-600/20' : 'bg-gray-50 text-gray-500 ring-gray-200 hover:bg-gray-100'">
                                    {{ p }}
                                </button>
                            </div>
                        </div>
                    </Panel>
                    <Panel v-else><p class="text-sm text-gray-400">{{ t('Select a role to edit its permissions.') }}</p></Panel>
                </div>
            </div>

            <!-- Users -->
            <div v-else-if="tab === 'users'" class="grid grid-cols-12 gap-5">
                <div class="col-span-12 lg:col-span-4">
                    <Panel :title="t('Users')">
                        <input v-model="userQuery" @keyup.enter="searchUsers" :placeholder="t('Search name / email…')" class="mb-2 w-full rounded-md border-gray-300 text-sm" />
                        <div v-for="u in users" :key="u.uid" @click="openUser(u)"
                            class="cursor-pointer rounded-lg px-2 py-1.5 text-sm hover:bg-gray-50"
                            :class="selectedUser && selectedUser.uid === u.uid ? 'bg-op-soft' : ''">
                            <div class="font-medium text-gray-700">{{ u.name }}</div>
                            <div class="text-xs text-gray-400">{{ u.roles.join(', ') || '—' }}</div>
                        </div>
                    </Panel>
                </div>
                <div class="col-span-12 lg:col-span-8">
                    <Panel v-if="selectedUser" :title="selectedUser.name" :subtitle="`${selectedUser.email} · ${selectedUser.operatorCode}`">
                        <template #actions>
                            <button @click="saveUserRoles" class="rounded-md bg-op px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">{{ t('Save roles') }}</button>
                        </template>
                        <div class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ t('Roles') }}</div>
                        <div class="mb-4 flex flex-wrap gap-1.5">
                            <button v-for="r in roles" :key="r.code" @click="toggleUserRole(r.code)"
                                class="rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition"
                                :class="selectedUser.roles.includes(r.code) ? 'bg-op text-white ring-op' : 'bg-gray-50 text-gray-500 ring-gray-200 hover:bg-gray-100'">
                                {{ r.code }}
                            </button>
                        </div>
                        <div v-if="effective" class="rounded-lg bg-gray-50 p-3">
                            <div class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ t('Effective access (computed)') }}</div>
                            <div class="flex flex-wrap gap-1">
                                <span v-for="p in effective.permissions" :key="p" class="rounded bg-white px-1.5 py-0.5 text-xs text-gray-600 ring-1 ring-gray-200">{{ p }}</span>
                            </div>
                        </div>
                    </Panel>
                    <Panel v-else><p class="text-sm text-gray-400">{{ t('Select a user to manage their roles.') }}</p></Panel>
                </div>
            </div>

            <!-- Audit -->
            <Panel v-else :title="t('Change audit')" :subtitle="t('Immutable record of every catalog change')">
                <DataTable :columns="auditColumns" :rows="audit" row-key="audit_id" empty="No audit entries.">
                    <template #cell-created_at="{ value }"><span class="text-xs text-gray-500">{{ value }}</span></template>
                    <template #cell-change_type="{ value }"><StatusBadge :status="value" /></template>
                    <template #cell-target="{ row }"><span class="text-xs">{{ row.target_type }}: {{ row.target_id }}</span></template>
                    <template #cell-actor_user_id="{ value }"><span class="text-xs">{{ value ?? '—' }}</span></template>
                </DataTable>
            </Panel>
        </div>
    </AuthenticatedLayout>
</template>
