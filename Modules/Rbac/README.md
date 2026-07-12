# RBAC

Authorization capability for roles, permissions, scopes, assignments and audited access-control changes.

## Use

Protect routes and commands with named permissions. Administrators use `routes/api.php` and `sophix:rbac:*` review commands to inspect effective access.

## Configure

Role-to-permission mappings and operator scopes are configuration seeded or administered through controlled APIs. Assignment/change history is audit data.

## Extend

Add permissions alongside the capability that needs them, then map roles without hard-coding user identities. Keep cross-operator access explicit, deny by default, and test both allowed and forbidden paths.

## Test

Scope, permission and administration scenarios are in `tests/Feature`.
