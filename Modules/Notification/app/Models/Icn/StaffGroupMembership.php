<?php

namespace Modules\Notification\Models\Icn;

use Illuminate\Database\Eloquent\Model;

/**
 * FOUNDATION_AUTH group-membership stand-in. The DD resolves a candidate_group to staff users
 * via GET /api/groups/{group}/members; in this deployment that directory is backed by this
 * table, read through StaffGroupDirectory so a real AUTH integration swaps in behind the seam.
 */
class StaffGroupMembership extends Model
{
    protected $table = 'staff_group_membership';

    protected $guarded = [];

    public static function members(string $operator, string $group): array
    {
        return static::query()->where('operator_code', $operator)->where('group_code', $group)
            ->pluck('user_id')->all();
    }
}
