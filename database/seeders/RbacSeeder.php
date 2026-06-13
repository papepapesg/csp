<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the canonical RBAC catalog (DD_EM-CFG-03).
 *
 * Business roles and permission codes are stable across operators. The full RBAC
 * admin module (CRUD, scopes, effective-access API) is a later deliverable; this
 * seed establishes the authoritative role/permission names the modules check.
 */
class RbacSeeder extends Seeder
{
    /** Canonical permission codes (DD_EM-CFG-03 permission catalog). */
    private const PERMISSIONS = [
        'customer.read', 'customer.update', 'customer.create',
        'subscription.read', 'subscription.create', 'subscription.activate', 'subscription.manage',
        'catalog.read', 'catalog.manage',
        'invoice.read', 'invoice.manage', 'payment.read', 'payment.apply', 'payment.reverse', 'dunning.admin', 'tax.compliance',
        'wallet.read', 'wallet.manage', 'adjustment.create', 'adjustment.approve',
        'ticket.create', 'ticket.read', 'ticket.assign',
        'fulfillment.read', 'fulfillment.manage',
        'workorder.read', 'workorder.assign', 'workorder.execute',
        'stock.read', 'stock.manage',
        'report.view',
        'notification.read', 'notification.send', 'notification.manage', 'notification.template.manage',
        'staff_notification.dispatch', 'staff_notification.manage',
        'ticket.read', 'ticket.create', 'ticket.assign', 'ticket.manage',
        'franchise.manage', 'rbac.manage',
        'platform.cross_operator',
        'workforce.read', 'workforce.manage',
        'workflow.view', 'workflow.manage',
        'itops.view', 'itops.manage',
        'provisioning.view', 'provisioning.manage',
        'rules.view', 'rules.manage',
        'selfcare.access',
    ];

    /** Role -> granted permissions (DD_EM-CFG-03 role-permission matrix). */
    private const ROLES = [
        'SUPER_ADMIN' => ['*'],
        'RBAC_ADMIN' => ['rbac.manage'],
        'CUSTOMER_CARE_AGENT' => ['customer.read', 'customer.update', 'ticket.create', 'ticket.read', 'ticket.manage', 'notification.read', 'notification.send', 'subscription.read', 'invoice.read', 'payment.read', 'wallet.read'],
        'CUSTOMER_CARE_SUPERVISOR' => ['customer.read', 'customer.update', 'ticket.create', 'ticket.read', 'ticket.assign', 'ticket.manage', 'notification.read', 'notification.send', 'subscription.read', 'invoice.read'],
        'BILLING_OPERATOR' => ['invoice.read', 'invoice.manage', 'payment.read', 'payment.apply', 'payment.reverse', 'dunning.admin', 'wallet.read', 'wallet.manage', 'adjustment.create', 'customer.read', 'subscription.read'],
        'BILLING_LEAD' => ['invoice.read', 'invoice.manage', 'payment.read', 'payment.apply', 'payment.reverse', 'dunning.admin', 'wallet.read', 'wallet.manage', 'adjustment.create', 'adjustment.approve', 'customer.read', 'subscription.read', 'report.view'],
        // BIL-04 dunning service account. The only role allowed to trigger non-payment
        // suspension (DD_SUB-WF-SUSPEND-NP-01 R-T-1); also drives dunning restrictions.
        'BILLING_INTERNAL' => ['invoice.read', 'payment.read', 'subscription.read', 'subscription.manage'],
        'PRODUCT_MANAGER' => ['catalog.read', 'catalog.manage'],
        'CATALOG_ADMIN' => ['catalog.read', 'catalog.manage'],
        'OSR_OPERATOR' => ['stock.read', 'stock.manage'],
        'OSR_SUPERVISOR' => ['stock.read', 'stock.manage', 'report.view', 'provisioning.view', 'provisioning.manage'],
        'SALES_AGENT' => ['customer.read', 'customer.create', 'subscription.read', 'fulfillment.read'],
        'SALES_SUPERVISOR' => ['customer.read', 'customer.create', 'subscription.read', 'fulfillment.read', 'franchise.manage', 'report.view'],
        'DISPATCHER' => ['workorder.read', 'workorder.assign', 'fulfillment.read', 'workforce.read'],
        'FIELD_TECHNICIAN' => ['workorder.read', 'workorder.execute', 'stock.read'],
        'CUSTOMER' => ['selfcare.access'],
        // NOT-01 notification operations team (FOUNDATION_AUTH roles).
        'NOTIFICATION_SENDER' => ['notification.read', 'notification.send', 'notification.manage'],
        'TEMPLATE_MANAGER' => ['notification.read', 'notification.template.manage'],
        // ICN-01 staff comms: workflow service accounts dispatch; ops admins manage.
        'ICN_SERVICE' => ['staff_notification.dispatch'],
        'ICN_ADMIN' => ['staff_notification.dispatch', 'staff_notification.manage'],
        // BIL-04 dunning: admin overrides vs read-only investigation.
        'DUNNING_ADMIN' => ['invoice.read', 'invoice.manage', 'dunning.admin'],
        'DUNNING_OPERATOR' => ['invoice.read'],
        // BIL-02-TAX-01: signed-tax-invoice cancellation requires this role (C-1 dual approval).
        'TAX_COMPLIANCE_OFFICER' => ['invoice.read', 'invoice.manage', 'tax.compliance'],
        // EM-03 CVM commercial team: manage retention activities/offers, approve high-value offers.
        'CVM_MANAGER' => ['customer.read', 'customer.update', 'subscription.read'],
    ];

    public function run(): void
    {
        // Single guard ('web'): Inertia SPA sessions and Sanctum-token API users
        // both resolve permissions against the User model's default guard.
        foreach (self::PERMISSIONS as $code) {
            Permission::findOrCreate($code, 'web');
        }

        foreach (self::ROLES as $roleCode => $permissions) {
            $role = Role::findOrCreate($roleCode, 'web');
            $grants = $permissions === ['*'] ? self::PERMISSIONS : $permissions;
            $role->syncPermissions(array_map(fn ($p) => Permission::findByName($p, 'web'), $grants));
        }

        // Bust Spatie's permission cache so freshly-seeded permissions/grants are honoured by
        // can() immediately (otherwise a stale in-process cache hides new permissions).
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
