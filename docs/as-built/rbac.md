# RBAC — As-Built Design (EM-CFG-03)

> **Capability codes:** EM-CFG-03 (roles, permissions, scopes) · **Module path:** `Modules/Rbac`
> **Test:** `RbacApiTest` · **See `00_SPINE.md` §7.**

## 1. Purpose & boundaries
- **Owns:** the access model — **roles → permissions** (spatie), **data scopes** (operator/franchise/
  tech-region/team/…), permission/role metadata, an immutable change audit.
- **Does NOT own:** authentication (Sanctum/Keycloak via `SOPHIX_AUTH_DRIVER`). It answers *what can this
  principal do, and where*.
- **Job:** "JWT → permission → scope" — the request guard every module relies on.

## 📖 Scenarios (service + Foundation involvement)

### 1. Grant a role + a region scope
`POST /api/rbac/users/{u}/roles {role:DISPATCHER}` + `RbacScopeService::assign(u, {scopeType:
TECH_REGION, scopeValue:'KE-NRB-KAREN'})`. *Operator admin.*

### 2. Permission check on a route
A route's `permission:workorder.assign` middleware (spatie) blocks a `CUSTOMER_CARE_AGENT` (403) but
passes a `DISPATCHER`. *Foundation: the permission guard.*

### 3. Scope gate allows within-region
The region-scoped dispatcher `POST /api/work-orders {tech_region_id:'KE-NRB-KAREN'}` → after permission,
`scope:TECH_REGION,tech_region_id` (`EnforceScope`) → `withinScope` true → allowed. *Proven by
`WorkOrderApiTest::test_tech_region_scope_gates_work_order_creation`.*

### 4. Out-of-region → 403
Same user posts `tech_region_id:'KE-MSA-NYALI'` → `withinScope` false → **403 OUT_OF_SCOPE**.

### 5. SUPER_ADMIN / GLOBAL bypass
A `SUPER_ADMIN` (or a GLOBAL/OPERATOR scope holder) bypasses the scope check entirely.

### 6. Change a role's permissions (audited)
`PUT /api/rbac/roles/DISPATCHER/permissions` → updates grants + writes an immutable `rbac_change_audit`
row. *Foundation: every RBAC change is traceable.*

### 7. Frontend action matrix
`rbac_frontend_action` maps a UI action → permission; the BO UI reads it to show/hide controls.

### 8. Revoke a scope
`RbacScopeService::revoke` deactivates a scope (with `effective_to`) + audits it; the user immediately
loses access to that region.

## 2. Data model — ≥4 sample rows + readings

### `rbac_user_scope_assignment` · `scope_type`: `GLOBAL|OPERATOR|FRANCHISE|TECH_REGION|CONTRACTOR|TEAM|CHANNEL`
```json
{ "scope_assignment_id":"usa_1","operator_code":"WIK","auth_user_id":"u_1","scope_type":"TECH_REGION","scope_value":"KE-NRB-KAREN","scope_label":"Karen","effective_from":"2026-06-01T00:00:00Z","effective_to":null,"active":true,"created_by_user_id":"admin_1" }
{ "scope_assignment_id":"usa_2","operator_code":"WIK","auth_user_id":"u_2","scope_type":"OPERATOR","scope_value":"WIK","scope_label":"Wik Telecom","effective_from":null,"effective_to":null,"active":true,"created_by_user_id":"admin_1" }
{ "scope_assignment_id":"usa_3","operator_code":"WIK","auth_user_id":"u_3","scope_type":"GLOBAL","scope_value":"*","scope_label":null,"effective_from":null,"effective_to":null,"active":true,"created_by_user_id":"admin_1" }
{ "scope_assignment_id":"usa_4","operator_code":"WIK","auth_user_id":"u_1","scope_type":"TECH_REGION","scope_value":"KE-MSA-NYALI","scope_label":"Nyali","effective_from":"2026-06-01T00:00:00Z","effective_to":"2026-06-15T00:00:00Z","active":false,"created_by_user_id":"admin_2" }
```
**Reading:** `withinScope(user, type, value)` is true if the user holds **GLOBAL**, an **OPERATOR** scope
matching the tenant, or an **exact** type+value. u_1 is scoped to Karen only (usa_4 to Mombasa/Nyali is
revoked — `active:false` with an `effective_to` close date → no access there). u_2 sees all of WIK; u_3
is platform-wide. SUPER_ADMIN bypasses regardless. `effective_from`/`effective_to` bound a scope's
validity window.

### `rbac_permission_meta` (`scope_required`) & roles/permissions (spatie)
```json
{ "permission_code":"workorder.assign","module_code":"WorkOrder","action_group":"assignment","risk_level":"MEDIUM","description":"Assign a work order to a technician","scope_required":true,"status":"ACTIVE" }
{ "permission_code":"billing.manage","module_code":"Billing","action_group":"billing","risk_level":"HIGH","description":"Manage billing artefacts","scope_required":false,"status":"ACTIVE" }
{ "permission_code":"customer.read","module_code":"Ilm","action_group":"customer","risk_level":"LOW","description":"Read customer records","scope_required":false,"status":"ACTIVE" }
{ "permission_code":"rbac.manage","module_code":"Rbac","action_group":"admin","risk_level":"CRITICAL","description":"Manage roles, permissions and scopes","scope_required":false,"status":"ACTIVE" }
{ "role":{ "id":4,"name":"DISPATCHER","guard_name":"api" } }
{ "permission":{ "id":12,"name":"workorder.assign","guard_name":"api" } }
```
**Reading:** `scope_required=true` marks the permissions whose routes should carry a `scope:` gate
(workorder.assign is region-scoped). The spatie `role`/`permission` rows (`id`, `name`, `guard_name`) are
the enforcement engine — a role's grants are the "what can you do"; scopes are the "where". `risk_level`
(up to `CRITICAL`) drives BO confirmation UX.

### `rbac_change_audit` (immutable) · `change_type`: `ROLE_CREATED|ROLE_PERMISSIONS_SYNCED|PERMISSION_CREATED|USER_ROLE_ASSIGNED|USER_SCOPE_…`
```json
{ "audit_id":"rba_1","operator_code":"WIK","change_type":"USER_SCOPE_ASSIGNED","target_type":"USER_ROLE","target_id":"u_1","actor_user_id":"admin_1","before_json":null,"after_json":{"scopeType":"TECH_REGION","scopeValue":"KE-NRB-KAREN"},"reason_code":"ONBOARDING" }
{ "audit_id":"rba_2","operator_code":"WIK","change_type":"ROLE_PERMISSIONS_SYNCED","target_type":"ROLE","target_id":"DISPATCHER","actor_user_id":"admin_1","before_json":{"permissions":["workorder.read"]},"after_json":{"permissions":["workorder.read","workorder.assign"]},"reason_code":null }
{ "audit_id":"rba_3","operator_code":"WIK","change_type":"USER_SCOPE_REVOKED","target_type":"USER_ROLE","target_id":"u_1","actor_user_id":"admin_2","before_json":{"scopeValue":"KE-MSA-NYALI"},"after_json":{"active":false},"reason_code":"TRANSFER" }
{ "audit_id":"rba_4","operator_code":"WIK","change_type":"USER_ROLE_ASSIGNED","target_type":"USER_ROLE","target_id":"u_2","actor_user_id":"admin_1","before_json":null,"after_json":{"role":"BILLING_LEAD"},"reason_code":null }
```
**Reading:** every grant/revoke/role change writes an append-only audit row — who (`actor_user_id`), what
(`change_type` on `target_type`/`target_id`), before/after (`before_json`/`after_json`) and an optional
`reason_code` — so RBAC changes are themselves fully traceable.

## 3. Services & middleware
| Component | Responsibility |
| --- | --- |
| `RbacScopeService` | `assign`/`revoke`/`scopesFor`/**`withinScope`** (GLOBAL/OPERATOR/exact + SUPER_ADMIN bypass), `permissionRequiresScope` |
| `Http/Middleware/EnforceScope` (`scope`) | request-time data-scope gate `scope:{type},{key}` (after `permission`) |

## 4. API surface
`/api/rbac/{roles,permissions,users,audit}`, `…/roles/{code}/permissions`, `…/users/{user}/roles`,
`…/users/{user}/within-scope`. `permission:rbac.{view,manage}`.

## 5. Integration
- **Emits:** `UserScope{Assigned,Revoked}` + change-audit.
- **Used by →** every route (`permission:` + optional `scope:`); the BO UI reads the action matrix.

## 6. Processes
Synchronous guards; no workflow.

## 7. Policy & config
Roles, permissions, scope assignments, `scope_required` flags — operator data; `SOPHIX_AUTH_DRIVER`
(sanctum|keycloak).

## 8. Cross-module dependencies
- **Used by →** all modules (the `permission`/`scope` middleware aliases in `bootstrap/app.php`).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| JWT → permission → scope | the documented guard order | route middleware stack |
| EM-CFG-03 §8.5 | a scope-sensitive route checks the target is within the caller's scopes | `EnforceScope`+`withinScope` |
| audited | every RBAC change writes an immutable audit row | `RbacChangeAudit` |

## 10. Open items / deltas
- Request-time scope enforcement (`scope` middleware) + the SUPER_ADMIN bypass were wired during
  hardening (previously `withinScope` was only called by a debug endpoint). Routes opt in per-need.
