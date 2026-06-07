<?php

namespace Modules\Rbac\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $role->syncPermissions($data['permissions']);
        }

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
        $role->syncPermissions($data['permissions']);

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

        return ApiResponse::created(['code' => $data['code']]);
    }

    /** POST /api/rbac/users/{user}/roles — assign roles to a user. */
    public function assignRoles(Request $request, string $user): JsonResponse
    {
        $data = $request->validate(['roles' => ['present', 'array']]);
        $u = User::query()->where('uid', $user)->when(is_numeric($user), fn ($q) => $q->orWhere('id', (int) $user))->firstOrFail();
        $u->syncRoles($data['roles']);

        return ApiResponse::item(['userId' => $u->uid, 'roles' => $u->getRoleNames()]);
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
