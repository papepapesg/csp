<?php

/*
|--------------------------------------------------------------------------
| SOPHIX app portals (FE-APP-01 multi-app separation)
|--------------------------------------------------------------------------
| Each app is its own subdomain (slug.<base_domain>) sharing one Laravel backend,
| one SSO session and the same component kit. The launcher (app.<base>) shows the
| tiles a user may open; each app's sidebar shows only its own nav. Access is gated
| by the listed permission (UI convenience; API permission middleware is the real
| enforcement). Add an app = a row here, not code.
*/

return [
    'base_domain' => env('SOPHIX_APP_BASE_DOMAIN', 'localhost'),
    'launcher_slug' => env('SOPHIX_LAUNCHER_SLUG', 'app'),

    'apps' => [
        'crm' => [
            'label' => 'CRM', 'tagline' => 'Customers, accounts & 360', 'color' => '#2563eb', 'perm' => 'customer.read',
            'nav' => [
                ['Customers', [
                    ['customers.index', 'Customers', 'customer.read'],
                    ['subscriptions.index', 'Subscriptions', 'subscription.read'],
                ]],
            ],
        ],
        'ops' => [
            'label' => 'Operations', 'tagline' => 'Fulfilment, work orders, billing, support', 'color' => '#0891b2', 'perm' => null,
            'nav' => [
                ['Run', [
                    ['dashboard', 'Dashboard', null],
                    ['fulfillment.console', 'Fulfillment', 'fulfillment.read'],
                    ['workorders.console', 'Work Orders', 'workorder.read'],
                    ['tickets.index', 'Tickets', 'ticket.read'],
                ]],
                ['Money & stock', [
                    ['billing.console', 'Billing', 'invoice.read'],
                    ['equipment.console', 'Equipment', 'stock.read'],
                    ['warehouse.console', 'Warehouse', 'stock.read'],
                    ['workforce.console', 'Workforce', 'workforce.read'],
                ]],
                ['Monitor', [['noc.console', 'NOC', 'itops.view']]],
            ],
        ],
        'catalog' => [
            'label' => 'Catalog', 'tagline' => 'Services, packages, tax, discounts', 'color' => '#7c3aed', 'perm' => 'catalog.read',
            'nav' => [['Configure', [
                ['catalog.setup', 'Catalog setup', 'catalog.read'],
                ['commercial.studio', 'Commercial', 'catalog.read'],
            ]]],
        ],
        'settings' => [
            'label' => 'Settings', 'tagline' => 'Users, roles, system config', 'color' => '#475569', 'perm' => 'rbac.manage',
            'nav' => [['Admin', [
                ['rbac.admin', 'RBAC & users', 'rbac.manage'],
                ['itops.console', 'IT-Ops', 'itops.view'],
                ['admin.console', 'Platform admin', 'itops.view'],
            ]]],
        ],
        'studio-workflow' => [
            'label' => 'Workflow Studio', 'tagline' => 'Design process flows', 'color' => '#db2777', 'perm' => 'workflow.view',
            'nav' => [['Workflow', [
                ['workflow.studio', 'Designer', 'workflow.view'],
                ['workflow.ops', 'Operations', 'workflow.view'],
            ]]],
        ],
        'studio-rules' => [
            'label' => 'Rules Studio', 'tagline' => 'Decision tables', 'color' => '#4f46e5', 'perm' => 'rules.view',
            'nav' => [['Rules', [['rules.studio', 'Decision tables', 'rules.view']]]],
        ],
        'studio-dunning' => [
            'label' => 'Dunning Studio', 'tagline' => 'Collections ladders', 'color' => '#ea580c', 'perm' => 'dunning.admin',
            'nav' => [['Dunning', [['dunning.studio', 'Programs', 'dunning.admin']]]],
        ],
        'reporting' => [
            'label' => 'Reporting', 'tagline' => 'Dashboards & exports', 'color' => '#059669', 'perm' => 'report.view',
            'nav' => [['Reports', [['reports.index', 'Dashboards', 'report.view']]]],
        ],
        'templates' => [
            'label' => 'Templates', 'tagline' => 'Notification & invoice design', 'color' => '#0d9488', 'perm' => 'notification.read',
            'nav' => [['Templates', [['templates.studio', 'Template studio', 'notification.read']]]],
        ],
        'brand' => [
            'label' => 'Brand & Locale', 'tagline' => 'Theme & translations', 'color' => '#9333ea', 'perm' => null,
            'nav' => [['Localize', [['i18n.studio', 'Localization', null]]]],
        ],
    ],
];
