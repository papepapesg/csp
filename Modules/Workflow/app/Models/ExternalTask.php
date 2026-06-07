<?php

namespace Modules\Workflow\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * External task — a service-task work item a module worker picks up by topic.
 *
 * @property string $task_id
 * @property string $topic
 * @property string $status
 */
class ExternalTask extends Model
{
    use HasPrefixedId;

    public const CREATED = 'CREATED';

    public const LOCKED = 'LOCKED';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    public const INCIDENT = 'INCIDENT';

    protected $table = 'workflow_external_task';

    protected $primaryKey = 'task_id';

    protected string $idPrefix = 'et';

    protected $guarded = [];

    protected $casts = ['variables' => 'array', 'locked_until' => 'datetime', 'completed_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'task_id';
    }
}
