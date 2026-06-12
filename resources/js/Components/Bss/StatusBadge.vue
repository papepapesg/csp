<script setup>
// Consistent status pill used across every backoffice surface. The colour map covers the
// lifecycle vocabularies of all modules (subscriptions, billing, fulfillment, WO, catalog,
// approvals) so a status always reads the same colour wherever it appears. Falls back to a
// neutral pill for unknown codes; the label flows through i18n.
import { computed } from 'vue';
import { useI18n } from '@/i18n';

const props = defineProps({
    status: { type: [String, null], default: null },
    // Optional caller override: { CODE: 'tailwind classes' }
    map: { type: Object, default: () => ({}) },
});
const { t } = useI18n();

const TONE = {
    green: 'bg-emerald-100 text-emerald-700 ring-emerald-600/20',
    red: 'bg-red-100 text-red-700 ring-red-600/20',
    amber: 'bg-amber-100 text-amber-700 ring-amber-600/20',
    blue: 'bg-blue-100 text-blue-700 ring-blue-600/20',
    indigo: 'bg-op-soft text-op ring-op',
    cyan: 'bg-cyan-100 text-cyan-700 ring-cyan-600/20',
    orange: 'bg-orange-100 text-orange-700 ring-orange-600/20',
    gray: 'bg-gray-100 text-gray-600 ring-gray-500/20',
};
// status code → tone. New codes degrade to gray; add a row here, never an if-branch elsewhere.
const BY_STATUS = {
    ACTIVE: 'green', APPROVED: 'green', AUTO_APPROVED: 'green', PAID: 'green', SIGNED: 'green', RESOLVED: 'green', COMPLETED: 'green', DONE: 'green', SENT: 'green', DELIVERED: 'green', PASS: 'green', SELLABLE: 'green',
    DRAFT: 'gray', PENDING: 'amber', PENDING_APPROVAL: 'amber', PENDING_SIGNATURE: 'amber', SCHEDULED: 'cyan', READY_FOR_REVIEW: 'blue', IN_REVIEW: 'blue', UNDER_REVIEW: 'blue', OPEN: 'blue', IN_PROGRESS: 'blue', WARN: 'amber',
    SUSPENDED: 'amber', RESTRICTED: 'amber', THROTTLED: 'amber', OVERDUE: 'red', FAILED: 'red', SIGNING_FAILED: 'red', REJECTED: 'red', CANCELLED: 'red', TERMINATED: 'red', BLOCKED: 'red', FAIL: 'red', GAVE_UP_AUTO: 'red', UNDELIVERABLE: 'red', QUARANTINE: 'red',
    END_OF_SALE: 'orange', END_OF_LIFE: 'orange', RETIRED: 'gray', INACTIVE: 'gray', CLOSED: 'gray', SUPERSEDED: 'gray', DISABLED: 'gray', ENDED: 'gray',
};
const tone = computed(() => TONE[BY_STATUS[props.status] ?? 'gray']);
const cls = computed(() => props.map[props.status] ?? tone.value);
</script>

<template>
    <span v-if="status" class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset" :class="cls">
        {{ t(String(status).replaceAll('_', ' ')) }}
    </span>
    <span v-else class="text-xs text-gray-400">—</span>
</template>
