<?php

namespace Modules\Rbac\Events;

/** EM-CFG-03 RBAC events (DD §12), published on the sophix.rbac topic. */
final class RbacEvents
{
    public const TOPIC = 'sophix.rbac';

    public const ROLE_CREATED = 'RbacRoleCreated';
    public const PERMISSION_CREATED = 'RbacPermissionCreated';
    public const ROLE_PERMISSION_CHANGED = 'RbacRolePermissionChanged';
    public const USER_ROLE_ASSIGNED = 'RbacUserRoleAssigned';
    public const USER_SCOPE_ASSIGNED = 'RbacUserScopeAssigned';
    public const USER_SCOPE_REVOKED = 'RbacUserScopeRevoked';
}
