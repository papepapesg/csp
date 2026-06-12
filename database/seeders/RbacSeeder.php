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
        'invoice.read', 'invoice.manage', 'payment.read', 'payment.apply', 'payment.reverse', 'dunning.admin',
        'wallet.read', 'wallet.manage', 'adjustment.create', 'adjustment.approve',
        'ticket.create', 'ticket.read', 'ticket.assign',
        'fulfillment.read', 'fulfillment.manage',
        'workorder.read', 'workorder.assign', 'workorder.execute',
        'stock.read', 'stock.manage',
        'report.view',
        'notification.read', 'notification.send', 'notification.manage', 'notification.template.manage',
        'ticket.read', 'ticket.create', 'ticket.assign', 'ticket.manage',
        'franchise.manage', 'rbac.manage',
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
    }
}
