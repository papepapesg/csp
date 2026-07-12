# RBAC

Authorization capability for roles, permissions, scopes, assignments and audited access-control changes.

## Use

Protect routes and commands with named permissions. Administrators use `routes/api.php` and `sophix:rbac:*` review commands to inspect effective access.

## Configure

Role-to-permission mappings and operator scopes are configuration seeded or administered through controlled APIs. Assignment/change history is audit data.

## Extend

Add permissions alongside the capability that needs them, then map roles without hard-coding user identities. Keep cross-operator access explicit, deny by default, and test both allowed and forbidden paths.

## Exposed APIs

- `GET rbac/audit`
- `GET rbac/frontend-actions`
- `GET rbac/permissions`
- `GET rbac/roles`
- `GET rbac/users`
- `GET rbac/users/{user}/effective-access`
- `GET rbac/users/{user}/navigation`
- `GET rbac/users/{user}/scopes`
- `GET rbac/users/{user}/within-scope`
- `POST rbac/frontend-actions`
- `POST rbac/permissions`
- `POST rbac/roles`
- `POST rbac/scopes/{scope}/revoke`
- `POST rbac/users/{user}/roles`
- `POST rbac/users/{user}/scopes`
- `PUT rbac/permissions/{code}/meta`
- `PUT rbac/roles/{code}/meta`
- `PUT rbac/roles/{code}/permissions`

## Data models

- `RbacChangeAudit`
- `RbacFrontendAction`
- `RbacPermissionMeta`
- `RbacRoleMeta`
- `RbacUserScope`

## Services

- `RbacScopeService`

## Events

- `RbacEvents`
- `RbacEvents::TOPIC`
- `RbacEvents::USER_SCOPE_ASSIGNED`
- `RbacEvents::USER_SCOPE_REVOKED`

## Commands

- `sophix:rbac:roles`
- `sophix:rbac:user-show`

## Test

Scope, permission and administration scenarios are in `tests/Feature`.
