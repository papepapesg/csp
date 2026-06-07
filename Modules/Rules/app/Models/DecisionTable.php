<?php

namespace Modules\Rules\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * A data-driven decision table (FOUNDATION_DROOLS as config).
 *
 * @property string $table_id
 * @property array $rules
 */
class DecisionTable extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';

    public const DEPLOYED = 'DEPLOYED';

    public const RETIRED = 'RETIRED';

    protected $table = 'decision_table';

    protected $primaryKey = 'table_id';

    protected string $idPrefix = 'dt';

    protected $guarded = [];

    protected $casts = ['inputs' => 'array', 'rules' => 'array', 'default_output' => 'array'];

    public function getRouteKeyName(): string
    {
        return 'table_id';
    }
}
