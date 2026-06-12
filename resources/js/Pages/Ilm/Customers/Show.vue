<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

// Customer 360 — the workshop's "single pane of glass any internal agent opens
// before any action" (ILM-CFG-01). Identity + KYC on the Customer; everything
// operational anchored on the Accounts (status/sub-status/flags); cross-module
// panels (subscriptions, invoices, tickets, interactions) read from each OWNING
// module's API — this page holds no business state.
const props = defineProps({ customerId: String });

const customer = ref(null);
const accounts = ref([]);
const flags = ref({});           // account_id -> active flags
const subscriptions = ref([]);
const invoices = ref([]);
const tickets = ref([]);
const notes = ref([]);
const interactions = ref([]);
const newNote = ref('');
const error = ref(null);
const panelErrors = ref({}); // per-panel availability — a failing panel degrades alone

// Customer 360 loads in ONE round trip via the resilient /overview composition: each panel
// resolves independently server-side, so a failing module (e.g. billing down) only blanks its
// own panel instead of breaking the whole screen.
async function load() {
    try {
        const { data } = await window.axios.get(`/api/customers/${props.customerId}/overview`);
        const p = data.panels ?? {};
        const ok = (name) => { panelErrors.value[name] = !(p[name]?.available); return p[name]?.data; };

        customer.value = ok('profile') ?? null;
        accounts.value = ok('accounts') ?? [];
        for (const acc of accounts.value) {
            flags.value[acc.accountId] = (acc.flags ?? []).map((code) => ({ flag_code: code }));
        }
        subscriptions.value = ok('subscriptions') ?? [];
        invoices.value = (ok('billing') ?? {}).recentInvoices ?? [];
        tickets.value = ok('tickets') ?? [];
        notes.value = ok('notes') ?? [];
        interactions.value = ok('interactions') ?? [];
    } catch (e) { error.value = e.response?.data?.message ?? 'Failed to load customer'; }
}
async function addNote() {
    if (!newNote.value) return;
    await window.axios.post(`/api/customers/${props.customerId}/notes`, { body: newNote.value });
    newNote.value = '';
    const n = await window.axios.get(`/api/customers/${props.customerId}/notes`);
    notes.value = n.data.items ?? [];
}
const subBadge = (s) => ({ ACTIVE: 'bg-green-100 text-green-700', SUSPENDED: 'bg-amber-100 text-amber-700', TERMINATED: 'bg-red-100 text-red-600' }[s] ?? 'bg-gray-100 text-gray-600');

onMounted(load);
</script>

<template>
    <Head :title="customer?.name ?? 'Customer'" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('customers.index')" class="text-sm text-gray-400 hover:text-gray-600">← Customers</Link>
                <h2 class="text-xl font-semibold text-gray-800">{{ customer?.name ?? '…' }}</h2>
                <span v-if="customer" class="rounded-full px-2 py-0.5 text-xs"
                    :class="customer.kycStatus === 'APPROVED' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">KYC {{ customer.kycStatus }}</span>
            </div>
        </template>

        <div class="py-6 mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-4">
            <p v-if="error" class="p-3 bg-red-100 text-red-700 rounded text-sm">{{ error }}</p>

            <div class="grid grid-cols-12 gap-4">
                <!-- Identity -->
                <div class="col-span-4 bg-white rounded shadow p-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Identity (legal entity)</div>
                    <dl v-if="customer" class="text-sm space-y-1">
                        <div class="flex justify-between"><dt class="text-gray-500">Customer ID</dt><dd class="font-mono text-xs">{{ customer.customerId }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Type</dt><dd>{{ customer.type }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">MSISDN</dt><dd>{{ customer.primaryMsisdn }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Email</dt><dd>{{ customer.email ?? '—' }}</dd></div>
                    </dl>
                </div>

                <!-- Accounts (operational anchor) -->
                <div class="col-span-8 bg-white rounded shadow p-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Accounts (operational anchor)</div>
                    <div v-for="a in accounts" :key="a.accountId ?? a.account_id" class="border rounded p-2 mb-2">
                        <div class="flex items-center gap-2 text-sm">
                            <span class="font-mono text-xs">{{ a.accountNumber ?? a.account_number }}</span>
                            <span class="text-xs px-1.5 py-0.5 rounded" :class="(a.status) === 'ACTIVE' ? 'bg-green-100 text-green-700' : 'bg-gray-100'">{{ a.status }}</span>
                            <span class="text-xs text-gray-400">{{ a.subStatus ?? a.sub_status }}</span>
                            <span class="text-xs text-gray-400">{{ a.serviceAddress ?? a.service_address }}</span>
                            <span v-for="f in flags[a.accountId ?? a.account_id] ?? []" :key="f.flag_code"
                                class="text-xs px-1.5 py-0.5 rounded bg-red-50 text-red-600 font-semibold">{{ f.flag_code }}</span>
                        </div>
                        <div v-if="a.attentionBanner ?? a.attention_banner" class="mt-1 text-xs bg-yellow-50 text-yellow-800 rounded px-2 py-1">
                            ⚠ {{ a.attentionBanner ?? a.attention_banner }}
                        </div>
                    </div>
                    <div v-if="!accounts.length" class="text-sm text-gray-400">No accounts.</div>
                </div>
            </div>

            <!-- Subscriptions -->
            <div class="bg-white rounded shadow p-4">
                <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Subscriptions (SUB-LM)</div>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500 uppercase"><th class="py-1">Id</th><th>Package</th><th>Status</th><th>Billing</th><th>HomePass</th></tr></thead>
                    <tbody>
                        <tr v-for="s in subscriptions" :key="s.subscription_id" class="border-t">
                            <td class="py-1 font-mono text-xs">{{ s.subscription_id }}</td>
                            <td>{{ s.package_ref }}</td>
                            <td><span class="text-xs px-1.5 py-0.5 rounded" :class="subBadge(s.status_code)">{{ s.status_code }}</span></td>
                            <td class="text-xs">{{ s.billing_mode }}</td>
                            <td class="text-xs">{{ s.homepass_id }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!subscriptions.length" class="text-sm text-gray-400">No subscriptions.</div>
            </div>

            <div class="grid grid-cols-12 gap-4">
                <!-- Invoices -->
                <div class="col-span-6 bg-white rounded shadow p-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Recent invoices (BIL)</div>
                    <div v-for="i in invoices" :key="i.invoice_id" class="flex justify-between text-sm border-t py-1">
                        <span class="font-mono text-xs">{{ i.legal_invoice_number ?? i.invoice_id }}</span>
                        <span>{{ i.currency }} {{ i.total_amount }}</span>
                        <span class="text-xs px-1.5 py-0.5 rounded" :class="i.status === 'PAID' ? 'bg-green-100 text-green-700' : i.status === 'OVERDUE' ? 'bg-red-100 text-red-600' : 'bg-gray-100'">{{ i.status }}</span>
                    </div>
                    <div v-if="!invoices.length" class="text-sm text-gray-400">No invoices.</div>
                </div>
                <!-- Tickets -->
                <div class="col-span-6 bg-white rounded shadow p-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Tickets (TCK)</div>
                    <div v-for="t in tickets" :key="t.ticket_id" class="flex justify-between text-sm border-t py-1">
                        <span class="truncate">{{ t.subject }}</span>
                        <span class="text-xs">{{ t.priority }}</span>
                        <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100">{{ t.status }}</span>
                    </div>
                    <div v-if="!tickets.length" class="text-sm text-gray-400">No tickets.</div>
                </div>
            </div>

            <div class="grid grid-cols-12 gap-4">
                <!-- Notes -->
                <div class="col-span-6 bg-white rounded shadow p-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Notes</div>
                    <div class="flex gap-2 mb-2">
                        <input v-model="newNote" @keyup.enter="addNote" placeholder="Add a note…" class="border rounded px-2 py-1 text-sm flex-1" />
                        <button @click="addNote" class="px-2 py-1 bg-indigo-600 text-white rounded text-sm">Add</button>
                    </div>
                    <div v-for="n in notes" :key="n.id" class="text-sm border-t py-1">
                        {{ n.body }} <span class="text-xs text-gray-400">— {{ n.created_at }}</span>
                    </div>
                </div>
                <!-- Interactions -->
                <div class="col-span-6 bg-white rounded shadow p-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Interaction history</div>
                    <div v-for="i in interactions" :key="i.id" class="text-sm border-t py-1">
                        <span class="text-xs px-1 bg-gray-100 rounded">{{ i.channel ?? i.kind }}</span>
                        {{ i.summary ?? i.body }} <span class="text-xs text-gray-400">{{ i.created_at }}</span>
                    </div>
                    <div v-if="!interactions.length" class="text-sm text-gray-400">No interactions.</div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
