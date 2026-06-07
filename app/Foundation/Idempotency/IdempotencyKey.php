<?php

namespace App\Foundation\Idempotency;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string|null $operator_code
 * @property string $request_hash
 * @property int|null $response_status
 * @property string|null $response_body
 */
class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    protected $fillable = [
        'key',
        'operator_code',
        'request_hash',
        'response_status',
        'response_body',
    ];
}
