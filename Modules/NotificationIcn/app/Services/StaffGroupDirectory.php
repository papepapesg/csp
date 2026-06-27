<?php

namespace Modules\Notification\Icn\Services;

use App\Models\User;
use Modules\Notification\Models\Icn\StaffGroupMembership;

/**
 * The FOUNDATION_AUTH directory boundary. Resolves a candidate_group to staff user ids
 * (GET /api/groups/{group}/members) and a user id to a profile (GET /api/users/{userId}).
 * Backed here by staff_group_membership + the User model; a real AUTH integration swaps in
 * behind this one class without touching dispatch.
 */
class StaffGroupDirectory
{
    /** @return list<string> staff user ids in the group */
    public function members(string $operator, string $group): array
    {
        return StaffGroupMembership::members($operator, $group);
    }

    /** @return array<string,mixed>|null the user's directory profile (email, name) */
    public function profile(string $userId): ?array
    {
        $user = User::query()->where('uid', $userId)->first();
        if (! $user) {
            return null;
        }

        return ['userId' => $userId, 'email' => $user->email, 'name' => $user->name, 'operatorCode' => $user->operator_code];
    }
}
