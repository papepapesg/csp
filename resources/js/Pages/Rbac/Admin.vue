<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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
        notice.value = `Saved ${currentRole.value.code}`; await load();
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
    await window.axios.post(`/api/rbac/users/${selectedUser.value.uid}/roles`, { roles: selectedUser.value.roles });
    notice.value = `Roles updated for ${selectedUser.value.name}`;
    await openUser(selectedUser.value); await searchUsers();
}

async function loadAudit() {
    const { data } = await window.axios.get('/api/rbac/audit', { params: { size: 50 } });
    audit.value = data.items ?? data.content ?? [];
}

onMounted(() => { load(); searchUsers(); loadAudit(); });
</script>

<template>
    <Head :title="t('RBAC Admin')" />
    <AuthenticatedLayout>
        <template #header><h2 class="font-semibold text-xl text-gray-800">{{ t('RBAC Admin — roles, permissions & access') }}</h2></template>

        <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4 flex gap-2">
                <button @click="tab = 'matrix'" :class="tab === 'matrix' ? 'bg-op text-white' : 'bg-white'" class="px-3 py-1 rounded border text-sm">{{ t('Role ⇄ permission matrix') }}</button>
                <button @click="tab = 'users'; searchUsers()" :class="tab === 'users' ? 'bg-op text-white' : 'bg-white'" class="px-3 py-1 rounded border text-sm">{{ t('User assignments') }}</button>
                <button @click="tab = 'audit'; loadAudit()" :class="tab === 'audit' ? 'bg-op text-white' : 'bg-white'" class="px-3 py-1 rounded border text-sm">{{ t('Change audit') }}</button>
            </div>

            <div v-if="error" class="mb-3 p-2 bg-red-100 text-red-700 rounded text-sm">{{ error }}</div>
            <div v-if="notice" class="mb-3 p-2 bg-green-100 text-green-700 rounded text-sm">{{ notice }}</div>

            <!-- Matrix -->
            <div v-if="tab === 'matrix'" class="grid grid-cols-12 gap-4">
                <div class="col-span-3 bg-white rounded shadow p-3">
                    <div class="flex gap-1 mb-3">
                        <input v-model="newRole" placeholder="NEW_ROLE" class="border rounded px-2 py-1 text-sm w-full" />
                        <button @click="createRole" class="px-2 bg-op text-white rounded text-sm">+</button>
                    </div>
                    <button v-for="r in roles" :key="r.code" @click="openRole(r)"
                        class="block w-full text-left px-2 py-1 rounded text-sm hover:bg-gray-100"
                        :class="currentRole && currentRole.code === r.code ? 'bg-op-soft font-semibold' : ''">
                        {{ r.code }} <span class="text-xs text-gray-400">({{ r.permissions.length }})</span>
                    </button>
                </div>

                <div v-if="currentRole" class="col-span-9 bg-white rounded shadow p-4">
                    <div class="flex justify-between items-center mb-3">
                        <div class="font-semibold">{{ currentRole.code }}</div>
                        <div class="flex gap-2">
                            <input v-model="newPermission" placeholder="module.action" class="border rounded px-2 py-1 text-sm" />
                            <button @click="createPermission" class="px-2 py-1 bg-gray-200 rounded text-sm">{{ t('+ permission') }}</button>
                            <button @click="saveMatrix" class="px-3 py-1 bg-op text-white rounded text-sm">{{ t('Save matrix') }}</button>
                        </div>
                    </div>
                    <div v-for="(perms, group) in permissionGroups" :key="group" class="mb-3">
                        <div class="text-xs font-semibold text-gray-500 uppercase mb-1">{{ group }}</div>
                        <div class="flex flex-wrap gap-1">
                            <button v-for="p in perms" :key="p" @click="toggle(p)"
                                class="px-2 py-0.5 rounded text-xs border"
                                :class="grantSet.has(p) ? 'bg-green-600 text-white border-green-600' : 'bg-gray-50 text-gray-500'">
                                {{ p }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Users -->
            <div v-else-if="tab === 'users'" class="grid grid-cols-12 gap-4">
                <div class="col-span-4 bg-white rounded shadow p-3">
                    <input v-model="userQuery" @keyup.enter="searchUsers" :placeholder="t('Search name / email…')" class="border rounded px-2 py-1 text-sm w-full mb-2" />
                    <div v-for="u in users" :key="u.uid" @click="openUser(u)"
                        class="px-2 py-1 rounded text-sm hover:bg-gray-100 cursor-pointer"
                        :class="selectedUser && selectedUser.uid === u.uid ? 'bg-op-soft' : ''">
                        {{ u.name }} <span class="text-xs text-gray-400">{{ u.roles.join(', ') || '—' }}</span>
                    </div>
                </div>
                <div v-if="selectedUser" class="col-span-8 bg-white rounded shadow p-4">
                    <div class="flex justify-between items-center mb-3">
                        <div class="font-semibold">{{ selectedUser.name }} <span class="text-xs text-gray-400">{{ selectedUser.email }} · {{ selectedUser.operatorCode }}</span></div>
                        <button @click="saveUserRoles" class="px-3 py-1 bg-op text-white rounded text-sm">{{ t('Save roles') }}</button>
                    </div>
                    <div class="flex flex-wrap gap-1 mb-4">
                        <button v-for="r in roles" :key="r.code" @click="toggleUserRole(r.code)"
                            class="px-2 py-0.5 rounded text-xs border"
                            :class="selectedUser.roles.includes(r.code) ? 'bg-op text-white border-op' : 'bg-gray-50 text-gray-500'">
                            {{ r.code }}
                        </button>
                    </div>
                    <div v-if="effective" class="bg-gray-50 rounded p-3">
                        <div class="text-xs font-semibold text-gray-500 mb-1">{{ t('Effective access (computed)') }}</div>
                        <div class="flex flex-wrap gap-1">
                            <span v-for="p in effective.permissions" :key="p" class="px-1.5 py-0.5 bg-gray-200 rounded text-xs">{{ p }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Audit -->
            <div v-else class="bg-white rounded shadow p-4">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500 uppercase">
                        <th class="py-1">{{ t('When') }}</th><th>{{ t('Change') }}</th><th>{{ t('Target') }}</th><th>{{ t('Actor') }}</th><th>{{ t('After') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="a in audit" :key="a.audit_id" class="border-t">
                            <td class="py-1 text-xs text-gray-500">{{ a.created_at }}</td>
                            <td>{{ a.change_type }}</td>
                            <td>{{ a.target_type }}: {{ a.target_id }}</td>
                            <td class="text-xs">{{ a.actor_user_id ?? '—' }}</td>
                            <td class="text-xs font-mono">{{ JSON.stringify(a.after_json) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
