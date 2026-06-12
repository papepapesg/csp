<?php

namespace Modules\Ilm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ILM-CFG-01 §5.3 KYC document — a reference to a file stored in FOUNDATION_FILE_STORAGE.
 * ILM holds the reference + content hash, never the bytes.
 */
class CustomerKycDocument extends Model
{
    protected $table = 'customer_kyc_document';

    protected $primaryKey = 'document_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['size_bytes' => 'integer', 'superseded_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'document_id';
    }
}
