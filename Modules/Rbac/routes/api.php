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
    Route::get('rbac/users', [RbacController::class, 'users']);
    Route::get('rbac/audit', [RbacController::class, 'audit']);
    Route::post('rbac/users/{user}/roles', [RbacController::class, 'assignRoles']);
    Route::get('rbac/users/{user}/effective-access', [RbacController::class, 'effectiveAccess']);

    // EM-CFG-03 §8.5 user scopes
    Route::get('rbac/users/{user}/scopes', [RbacController::class, 'userScopes']);
    Route::post('rbac/users/{user}/scopes', [RbacController::class, 'assignScope']);
    Route::post('rbac/scopes/{scope}/revoke', [RbacController::class, 'revokeScope']);
    Route::get('rbac/users/{user}/within-scope', [RbacController::class, 'withinScope']);

    // EM-CFG-03 catalog metadata + frontend action matrix
    Route::put('rbac/roles/{code}/meta', [RbacController::class, 'upsertRoleMeta']);
    Route::put('rbac/permissions/{code}/meta', [RbacController::class, 'upsertPermissionMeta']);
    Route::get('rbac/frontend-actions', [RbacController::class, 'frontendActions']);
    Route::post('rbac/frontend-actions', [RbacController::class, 'storeFrontendAction']);
    Route::get('rbac/users/{user}/navigation', [RbacController::class, 'navigation']);
});
