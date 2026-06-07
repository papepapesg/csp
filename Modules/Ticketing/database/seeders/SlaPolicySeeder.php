<?php

namespace Modules\Ticketing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ticketing\Models\SlaPolicy;

/** Default (global) SLA catalog; operators may override per category/priority. */
class SlaPolicySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['URGENT' => 4, 'HIGH' => 8, 'NORMAL' => 24, 'LOW' => 72] as $priority => $hours) {
            SlaPolicy::query()->updateOrCreate(
                ['operator_code' => null, 'category' => null, 'priority' => $priority],
                ['response_hours' => $hours],
            );
        }
    }
}
