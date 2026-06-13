<?php

namespace Modules\Rbac\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rbac\Models\RbacChangeAudit;
use Modules\Rbac\Models\RbacFrontendAction;
use Modules\Rbac\Models\RbacPermissionMeta;
use Modules\Rbac\Models\RbacRoleMeta;
use Modules\Rbac\Models\RbacUserScope;
use Modules\Rbac\Services\RbacScopeService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * EM-CFG-03 RBAC catalog — runtime management of roles, permissions, the role-permission matrix,
 * user role + scope assignments, and the frontend action-visibility matrix. Spatie is the
 * enforcement engine; this layers the DD's catalog metadata, scopes, and UI matrix on top.
 */
class RbacController extends ApiController
{
    public function __construct(private readonly RbacScopeService $scopes) {}

    public function roles(): JsonResponse
    {
        $roles = Role::query()->with('permissions:id,name')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'code' => $r->name,
                'guard' => $r->guard_name,
                'permissions' => $r->permissions->pluck('name'),
            ]);

        return ApiResponse::item(['items' => $roles]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'permissions' => ['nullable', 'array'],
        ]);
        $role = Role::findOrCreate($data['code'], 'web');
        if (isset($data['permissions'])) {
            foreach ($data['permissions'] as $p) {
                Permission::findOrCreate($p, 'web');
            }
            $role->syncPermissions($data['permissions']);
        }

        // EM-CFG-03 §8.8: every RBAC change is audited.
        RbacChangeAudit::record('ROLE_CREATED', 'ROLE', $role->name, null,
            ['roleCode' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()],
            $request->user()?->uid);

        return ApiResponse::created(['code' => $role->name, 'permissions' => $role->permissions->pluck('name')]);
    }

    /** PUT /api/rbac/roles/{code}/permissions — set the role's permission matrix. */
    public function syncPermissions(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['permissions' => ['present', 'array']]);
        $role = Role::findByName($code, 'web');
        // A non-super actor cannot grant a permission they do not themselves hold (no escalation).
        $actor = $request->user();
        if ($actor && ! $actor->hasRole('SUPER_ADMIN')) {
            $held = $actor->getAllPermissions()->pluck('name')->all();
            foreach ($data['permissions'] as $p) {
                if (! in_array($p, $held, true)) {
                    throw new \App\Foundation\Errors\DomainException('PERMISSION_CEILING',
                        "You cannot grant a permission you do not hold: {$p}", 403);
                }
            }
        }
        // Ensure each permission exists (catalog grows at runtime).
        foreach ($data['permissions'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $before = $role->permissions->pluck('name')->all();
        $role->syncPermissions($data['permissions']);

        RbacChangeAudit::record('ROLE_PERMISSIONS_SYNCED', 'ROLE', $role->name,
            ['permissions' => $before], ['permissions' => $data['permissions']], $request->user()?->uid);

        return ApiResponse::item(['code' => $role->name, 'permissions' => $role->fresh()->permissions->pluck('name')]);
    }

    public function permissions(): JsonResponse
    {
        return ApiResponse::item(['items' => Permission::query()->orderBy('name')->pluck('name')]);
    }

    public function storePermission(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:96']]);
        Permission::findOrCreate($data['code'], 'web');

        RbacChangeAudit::record('PERMISSION_CREATED', 'PERMISSION', $data['code'], null,
            ['permissionCode' => $data['code']], $request->user()?->uid);

        return ApiResponse::created(['code' => $data['code']]);
    }

    /** POST /api/rbac/users/{user}/roles — assign roles to a user. */
    public function assignRoles(Request $request, string $user): JsonResponse
    {
        $data = $request->validate(['roles' => ['present', 'array']]);
        $u = User::query()->where('uid', $user)->when(is_numeric($user), fn ($q) => $q->orWhere('id', (int) $user))->firstOrFail();

        // Assignment ceiling (anti-escalation): only SUPER_ADMIN may grant SUPER_ADMIN, and a
        // non-super actor may only assign roles they themselves hold — no self/lateral escalation.
        $this->assertWithinGrantCeiling($request->user(), $data['roles']);

        $before = $u->getRoleNames()->all();
        $u->syncRoles($data['roles']);

        RbacChangeAudit::record('USER_ROLE_ASSIGNED', 'USER_ROLE', $u->uid,
            ['roles' => $before], ['roles' => $data['roles']], $request->user()?->uid);

        return ApiResponse::item(['userId' => $u->uid, 'roles' => $u->getRoleNames()]);
    }

    /**
     * Anti-escalation ceiling: SUPER_ADMIN may grant anything; a non-super actor may only
     * assign roles they themselves hold, and may never grant SUPER_ADMIN.
     *
     * @param  array<int,string>  $roles
     */
    private function assertWithinGrantCeiling(?User $actor, array $roles): void
    {
        if ($actor && $actor->hasRole('SUPER_ADMIN')) {
            return;
        }
        $held = $actor ? $actor->getRoleNames()->all() : [];
        foreach ($roles as $role) {
            if ($role === 'SUPER_ADMIN' || ! in_array($role, $held, true)) {
                throw new \App\Foundation\Errors\DomainException('ROLE_CEILING',
                    "You cannot assign a role you do not hold: {$role}", 403);
            }
        }
    }

    /** GET /api/rbac/users — the user directory for the role-assignment screen. */
    public function users(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = User::query()
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")))
            ->orderBy('name')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1)
            ->through(fn (User $u) => [
                'uid' => $u->uid, 'name' => $u->name, 'email' => $u->email,
                'operatorCode' => $u->operator_code, 'roles' => $u->getRoleNames(),
            ]);

        return ApiResponse::paginated($page);
    }

    /** GET /api/rbac/audit — the EM-CFG-03 §8.8 change-audit feed. */
    public function audit(Request $request): JsonResponse
    {
        $params = $this->pageParams($request);
        $page = RbacChangeAudit::query()
            ->when($request->query('targetType'), fn ($q, $t) => $q->where('target_type', $t))
            ->when($request->query('targetId'), fn ($q, $t) => $q->where('target_id', $t))
            ->orderByDesc('created_at')
            ->paginate(perPage: $params['size'], page: $params['page'] + 1);

        return ApiResponse::paginated($page);
    }

    /** GET /api/rbac/users/{user}/effective-access — computed roles + permissions + scopes (DD §10.3). */
    public function effectiveAccess(string $user): JsonResponse
    {
        $u = $this->resolveUser($user);

        return ApiResponse::item([
            'userId' => $u->uid,
            'operatorCode' => $u->operator_code,
            'roles' => $u->getRoleNames(),
            'permissions' => $u->getAllPermissions()->pluck('name'),
            'scopes' => $this->scopes->scopesFor($u->uid)->map(fn (RbacUserScope $s) => ['type' => $s->scope_type, 'value' => $s->scope_value, 'label' => $s->scope_label]),
        ]);
    }

    // ---- scope assignment (DD §8.5) ----

    /** POST /api/rbac/users/{user}/scopes — grant a user a scope. */
    public function assignScope(Request $request, string $user): JsonResponse
    {
        $data = $request->validate([
            'scopeType' => ['required', 'in:OPERATOR,FRANCHISE,TECH_REGION,CONTRACTOR,TEAM,CHANNEL,GLOBAL'],
            'scopeValue' => ['required', 'string'], 'scopeLabel' => ['nullable', 'string'],
            'effectiveTo' => ['nullable', 'date'],
        ]);
        $u = $this->resolveUser($user);

        return ApiResponse::created($this->scopes->assign($u->uid, $data, $request->user()?->uid));
    }

    public function userScopes(string $user): JsonResponse
    {
        $u = $this->resolveUser($user);

        return ApiResponse::item(['userId' => $u->uid, 'scopes' => $this->scopes->scopesFor($u->uid)]);
    }

    public function revokeScope(RbacUserScope $scope): JsonResponse
    {
        return ApiResponse::item($this->scopes->revoke($scope, request()->user()?->uid));
    }

    /** GET /api/rbac/users/{user}/within-scope?scopeType=&scopeValue= — the enforcement check modules call. */
    public function withinScope(Request $request, string $user): JsonResponse
    {
        $data = $request->validate(['scopeType' => ['required', 'string'], 'scopeValue' => ['required', 'string']]);
        $u = $this->resolveUser($user);

        return ApiResponse::item(['within' => $this->scopes->withinScope($u->uid, $data['scopeType'], $data['scopeValue'])]);
    }

    // ---- catalog metadata (DD §8.1/8.2) ----

    public function upsertRoleMeta(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['nullable', 'string'], 'role_family' => ['nullable', 'string'],
            'description' => ['nullable', 'string'], 'status' => ['nullable', 'in:DRAFT,ACTIVE,RETIRED'], 'keycloak_role_name' => ['nullable', 'string'],
        ]);

        return ApiResponse::item(RbacRoleMeta::query()->updateOrCreate(['role_code' => $code], $data));
    }

    public function upsertPermissionMeta(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'module_code' => ['nullable', 'string'], 'action_group' => ['nullable', 'string'],
            'risk_level' => ['nullable', 'in:LOW,MEDIUM,HIGH,CRITICAL'], 'description' => ['nullable', 'string'],
            'scope_required' => ['nullable', 'boolean'], 'status' => ['nullable', 'in:ACTIVE,RETIRED'],
        ]);
        Permission::findOrCreate($code, 'web');

        return ApiResponse::item(RbacPermissionMeta::query()->updateOrCreate(['permission_code' => $code], $data));
    }

    // ---- frontend action matrix (DD §8.6/8.7) ----

    public function frontendActions(Request $request): JsonResponse
    {
        return ApiResponse::item(['items' => RbacFrontendAction::query()->when($request->query('appCode'), fn ($q, $a) => $q->where('app_code', $a))->orderBy('action_code')->get()]);
    }

    public function storeFrontendAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_code' => ['required', 'string'], 'action_code' => ['required', 'string'],
            'action_type' => ['required', 'in:MENU,SCREEN,BUTTON,TAB,FIELD'], 'display_name' => ['required', 'string'],
            'required_permission_code' => ['nullable', 'string'], 'feature_flag' => ['nullable', 'string'],
        ]);

        return ApiResponse::created(RbacFrontendAction::query()->updateOrCreate(['action_code' => $data['action_code']], $data));
    }

    /**
     * GET /api/rbac/users/{user}/navigation?appCode= — the actions a user may see. An action is
     * visible when the user holds its required_permission_code (UI convenience; never security).
     */
    public function navigation(Request $request, string $user): JsonResponse
    {
        $u = $this->resolveUser($user);
        $perms = $u->getAllPermissions()->pluck('name')->all();
        $actions = RbacFrontendAction::query()->where('status', 'ACTIVE')
            ->when($request->query('appCode'), fn ($q, $a) => $q->where('app_code', $a))->get()
            ->filter(fn (RbacFrontendAction $a) => $a->required_permission_code === null || in_array($a->required_permission_code, $perms, true))
            ->map(fn (RbacFrontendAction $a) => ['actionCode' => $a->action_code, 'type' => $a->action_type, 'displayName' => $a->display_name, 'app' => $a->app_code])
            ->values();

        return ApiResponse::item(['userId' => $u->uid, 'actions' => $actions]);
    }

    private function resolveUser(string $user): User
    {
        return User::query()->where('uid', $user)->when(is_numeric($user), fn ($q) => $q->orWhere('id', (int) $user))->firstOrFail();
    }
}
