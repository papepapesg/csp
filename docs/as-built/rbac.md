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

### `rbac_user_scope` · `scope_type`: `GLOBAL|OPERATOR|FRANCHISE|TECH_REGION|CONTRACTOR|TEAM|CHANNEL`
```json
{ "id":"us_1","auth_user_id":"u_1","scope_type":"TECH_REGION","scope_value":"KE-NRB-KAREN","active":true }
{ "id":"us_2","auth_user_id":"u_2","scope_type":"OPERATOR","scope_value":"WIK","active":true }
{ "id":"us_3","auth_user_id":"u_3","scope_type":"GLOBAL","scope_value":"*","active":true }
{ "id":"us_4","auth_user_id":"u_1","scope_type":"TECH_REGION","scope_value":"KE-MSA-NYALI","active":false }
```
**Reading:** `withinScope(user, type, value)` is true if the user holds **GLOBAL**, an **OPERATOR** scope
matching the tenant, or an **exact** type+value. u_1 is scoped to Karen only (us_4 to Mombasa is revoked
→ no access there). u_2 sees all of WIK; u_3 is platform-wide. SUPER_ADMIN bypasses regardless.

### `rbac_permission_meta` (`scope_required`) & roles/permissions (spatie)
```json
{ "permission_code":"workorder.assign","module":"WorkOrder","risk":"MEDIUM","scope_required":true }
{ "permission_code":"billing.manage","module":"Billing","risk":"HIGH","scope_required":false }
{ "permission_code":"customer.read","module":"Ilm","risk":"LOW","scope_required":false }
{ "role":{ "code":"DISPATCHER","permissions":["workorder.read","workorder.assign","workforce.read"] } }
```
**Reading:** `scope_required=true` marks the permissions whose routes should carry a `scope:` gate
(workorder.assign is region-scoped). The role→permission grant is the "what can you do"; scopes are the
"where". `risk` drives BO confirmation UX.

### `rbac_change_audit` (immutable)
```json
{ "id":"ca_1","action":"USER_SCOPE_ASSIGNED","entity":"u_1","new":{"scopeType":"TECH_REGION","scopeValue":"KE-NRB-KAREN"},"actor":"admin_1" }
{ "id":"ca_2","action":"ROLE_PERMISSIONS_UPDATED","entity":"DISPATCHER","actor":"admin_1" }
{ "id":"ca_3","action":"USER_SCOPE_REVOKED","entity":"u_1","actor":"admin_2" }
{ "id":"ca_4","action":"ROLE_ASSIGNED","entity":"u_2","new":{"role":"BILLING_LEAD"},"actor":"admin_1" }
```
**Reading:** every grant/revoke/role change writes an append-only audit row (who, what, before/after) —
RBAC changes are themselves fully traceable.

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
