<?php

namespace Modules\Workflow\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** Human (user) task. */
class UserTask extends Model
{
    use HasPrefixedId;

    public const OPEN = 'OPEN';

    public const CLAIMED = 'CLAIMED';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'workflow_user_task';

    protected $primaryKey = 'task_id';

    protected string $idPrefix = 'ut';

    protected $guarded = [];

    protected $casts = ['variables' => 'array', 'due_at' => 'datetime', 'completed_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'task_id';
    }
}
