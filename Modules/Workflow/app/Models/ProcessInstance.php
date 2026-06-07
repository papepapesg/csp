<?php

namespace Modules\Workflow\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * One running workflow execution.
 *
 * @property string $instance_id
 * @property array $variables
 * @property string $status
 */
class ProcessInstance extends Model
{
    use HasPrefixedId;

    public const RUNNING = 'RUNNING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    public const SUSPENDED = 'SUSPENDED';

    protected $table = 'process_instance';

    protected $primaryKey = 'instance_id';

    protected string $idPrefix = 'pi';

    protected $guarded = [];

    protected $casts = [
        'variables' => 'array',
        'active_nodes' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'instance_id';
    }
}
