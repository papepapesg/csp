<?php

namespace Modules\Rbac\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rbac\Models\RbacChangeAudit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * EM-CFG-03 RBAC catalog — runtime management of roles, permissions, the
 * role-permission matrix and user assignments. The seed is just initial data;
 * this is the authoritative, editable catalog (no hardcoded role constants).
 */
class RbacController extends ApiController
{
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
        $before = $u->getRoleNames()->all();
        $u->syncRoles($data['roles']);

        RbacChangeAudit::record('USER_ROLE_ASSIGNED', 'USER_ROLE', $u->uid,
            ['roles' => $before], ['roles' => $data['roles']], $request->user()?->uid);

        return ApiResponse::item(['userId' => $u->uid, 'roles' => $u->getRoleNames()]);
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

    /** GET /api/rbac/users/{user}/effective-access — computed roles + permissions. */
    public function effectiveAccess(string $user): JsonResponse
    {
        $u = User::query()->where('uid', $user)->when(is_numeric($user), fn ($q) => $q->orWhere('id', (int) $user))->firstOrFail();

        return ApiResponse::item([
            'userId' => $u->uid,
            'operatorCode' => $u->operator_code,
            'roles' => $u->getRoleNames(),
            'permissions' => $u->getAllPermissions()->pluck('name'),
        ]);
    }
}
