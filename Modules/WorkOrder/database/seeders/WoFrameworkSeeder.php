<?php

namespace Modules\WorkOrder\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\WorkOrder\Models\WoFinalizationRequirement;
use Modules\WorkOrder\Models\WoNoteKind;

/**
 * WO-01-FRAMEWORK §4.3/§4.4 seed: per-operator note-kind schemas and the finalize
 * checklist. Mirrors the DD's WIK sample rows.
 */
class WoFrameworkSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');

        // note_kind => JSON Schema (null = free-text). Validated in full by JsonSchemaValidator:
        // type, required, enum, numeric ranges (e.g. ontRxDbm > -40 and <= -8), patterns, nested.
        $kinds = [
            'findings' => null,
            'solution' => null,
            'dispatcher_note' => null,
            'final_reason_set' => [
                'type' => 'object',
                'required' => ['finalReasonCode'],
                'properties' => ['finalReasonCode' => ['type' => 'string', 'minLength' => 2]],
            ],
            // GPON optical readings: port string + ONT RX power in dBm (valid GPON window ~ -8..-28 dBm).
            'optical_readings' => [
                'type' => 'object',
                'required' => ['oltPort', 'ontRxDbm'],
                'properties' => [
                    'oltPort' => ['type' => 'string', 'pattern' => '^[0-9]+/[0-9]+/[0-9]+$'],
                    'ontRxDbm' => ['type' => 'number', 'exclusiveMinimum' => -40, 'maximum' => -8],
                ],
            ],
            'hfc_signal_readings' => [
                'type' => 'object',
                'required' => ['downstreamLevel', 'upstreamLevel', 'snr'],
                'properties' => [
                    'downstreamLevel' => ['type' => 'number'],
                    'upstreamLevel' => ['type' => 'number'],
                    'snr' => ['type' => 'number', 'minimum' => 0],
                ],
            ],
        ];
        foreach ($kinds as $kind => $schema) {
            WoNoteKind::query()->updateOrCreate(
                ['operator_code' => $operator, 'note_kind' => $kind],
                ['schema_jsonb' => $schema, 'append_only' => true],
            );
        }

        // (operator, kind, job_type=null default) => required note kinds.
        $reqs = [
            ['SUPPORT', null, ['findings', 'solution', 'final_reason_set']],
            ['INSTALLATION', null, ['findings', 'solution']],
            ['SHIFTING', null, ['dispatcher_note']],
        ];
        foreach ($reqs as [$kind, $jobType, $notes]) {
            WoFinalizationRequirement::query()->updateOrCreate(
                ['operator_code' => $operator, 'kind' => $kind, 'job_type_code' => $jobType],
                ['id' => Id::make('wofr'), 'required_note_kinds' => $notes, 'required_attachment_categories' => []],
            );
        }
    }
}
