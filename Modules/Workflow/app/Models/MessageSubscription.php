<?php

namespace Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;

/** Message-catch subscription (instance waits for a correlated message). */
class MessageSubscription extends Model
{
    protected $table = 'workflow_message_subscription';

    protected $guarded = [];
}
