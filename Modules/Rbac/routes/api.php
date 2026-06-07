<?php

use Illuminate\Support\Facades\Route;
use Modules\Rbac\Http\Controllers\RbacController;

/*
| EM-CFG-03 RBAC catalog API (DD_API-00). All under rbac.manage; effective-access
| readable with rbac.manage too (admin-facing).
*/

Route::middleware(['auth:sanctum', 'permission:rbac.manage'])->group(function () {
    Route::get('rbac/roles', [RbacController::class, 'roles']);
    Route::post('rbac/roles', [RbacController::class, 'storeRole']);
    Route::put('rbac/roles/{code}/permissions', [RbacController::class, 'syncPermissions']);
    Route::get('rbac/permissions', [RbacController::class, 'permissions']);
    Route::post('rbac/permissions', [RbacController::class, 'storePermission']);
    Route::post('rbac/users/{user}/roles', [RbacController::class, 'assignRoles']);
    Route::get('rbac/users/{user}/effective-access', [RbacController::class, 'effectiveAccess']);
});
