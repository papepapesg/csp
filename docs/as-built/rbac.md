# RBAC — As-Built Design (EM-CFG-03)

> **Capability codes:** EM-CFG-03 (roles, permissions, scopes) · **Module path:** `Modules/Rbac`
> **Source-of-truth test:** `RbacApiTest` · **See also `00_SPINE.md` §7.**

## 1. Purpose & boundaries
- **Owns:** the access model — **roles → permissions** (via spatie/laravel-permission), **data
  scopes** (operator / franchise / tech-region / team / …), permission/role metadata, and an
  immutable change audit.
- **Does NOT own:** authentication (Sanctum/Keycloak via `SOPHIX_AUTH_DRIVER`) — it answers
  *what can this principal do, and where*.
- **Job:** "JWT → permission → scope" — the request guard every module relies on.

## 📖 Scenarios — read these first

### Scenario A — a region-scoped dispatcher is kept in their lane
1. An admin grants a user the `DISPATCHER` role and a `TECH_REGION = KE-NRB-KAREN` scope
   (`RbacScopeService::assign`).
2. The user `POST /api/work-orders {tech_region_id:'KE-NRB-KAREN'}` → passes
   `permission:workorder.assign` then the `scope:TECH_REGION,tech_region_id` middleware
   (`EnforceScope`): `withinScope` is **true** (exact match) → allowed.
3. The same user posts `tech_region_id:'KE-MSA-NYALI'` → `withinScope` false → **403 OUT_OF_SCOPE**.
4. A `SUPER_ADMIN` (or a GLOBAL/OPERATOR scope holder) **bypasses** the scope check.
- **Proven by:** `WorkOrderApiTest::test_tech_region_scope_gates_work_order_creation`, `RbacApiTest`.

### Scenario B — change a role's permissions (audited)
- `PUT /api/rbac/roles/DISPATCHER/permissions` updates the grant and writes an `rbac_change_audit`
  row (who/what/when) — RBAC changes are themselves traceable.

## 2. Data model
| Table | Purpose | Invariants |
| --- | --- | --- |
| spatie `roles`/`permissions`/pivots | role→permission grants | per-guard |
| `rbac_user_scope` | a user's data scopes (type+value, validity window) | GLOBAL/OPERATOR/exact match |
| `rbac_permission_meta` / `rbac_role_meta` | `scope_required`, risk, module, action-group | metadata |
| `rbac_frontend_action` | UI action → permission map | drives the BO matrix |
| `rbac_change_audit` | immutable audit of RBAC changes | append-only |

## 3. Services & middleware
| Component | Responsibility |
| --- | --- |
| `RbacScopeService` | `assign`/`revoke`/`scopesFor`/**`withinScope`** (GLOBAL/OPERATOR/exact + SUPER_ADMIN bypass), `permissionRequiresScope` |
| `Http/Middleware/EnforceScope` (`scope`) | request-time data-scope gate `scope:{type},{key}` (after `permission`) |

## 4. API surface
`/api/rbac/{roles,permissions,users,audit}`, `…/roles/{code}/permissions`, `…/users/{user}/roles`,
`…/users/{user}/within-scope`. Guarded by `permission:rbac.{view,manage}`.

## 5. Integration
- **Emits:** `UserScope{Assigned,Revoked}` + change-audit.
- **Used by →** every route (`permission:` + optional `scope:` middleware); the BO UI reads the matrix.

## 6. Processes
Synchronous guards; no workflow.

## 7. Policy & config
Roles, permissions, scope assignments, `scope_required` flags — all operator data; auth backend via
`SOPHIX_AUTH_DRIVER` (sanctum|keycloak).

## 8. Cross-module dependencies
- **Used by →** all modules (the `permission`/`scope` middleware aliases registered in `bootstrap/app.php`).

## 9. Invariants & rules
| Rule | Statement | Enforced in |
| --- | --- | --- |
| JWT → permission → scope | the documented guard order | route middleware stack |
| EM-CFG-03 §8.5 | a scope-sensitive route checks the target is within the caller's scopes | `EnforceScope` + `withinScope` |
| audited | every RBAC change writes an immutable audit row | `RbacChangeAudit` |

## 10. Open items / deltas
- Request-time scope enforcement (`scope` middleware) + the SUPER_ADMIN bypass were wired during
  hardening (previously `withinScope` was only called by a debug endpoint). Routes opt in per-need.
