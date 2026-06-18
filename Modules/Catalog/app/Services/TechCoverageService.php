<?php

namespace Modules\Catalog\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Workforce\Models\Contractor;
use Modules\Catalog\Models\TechContractorSkill;
use Modules\Catalog\Models\TechRegion;

/**
 * RLM-CFG-01 territory & contractor coverage rules (C-/S-/T-/A- series). Skills belong to
 * contractors (not regions); a region→contractor assignment scopes a subset of the
 * contractor's skills (A-1); a region needs ≥1 active contractor to activate (T-7); and
 * neither a region nor a contractor can retire while an ACTIVE HomePass depends on it.
 */
class TechCoverageService
{
    /** @param array<string,mixed> $data code, name, description? */
    public function createSkill(array $data): TechContractorSkill
    {
        return TechContractorSkill::query()->create($data + ['operator_code' => Context::operatorCode(), 'status' => 'ACTIVE']);
    }

    /**
     * R-RLM-CFG-01-C-3: a contractor's skills must be a non-empty list of codes that all exist
     * in the active skill catalog.
     *
     * @param  array<string,mixed>  $data  code, name, skills[]
     */
    public function createContractor(array $data): Contractor
    {
        $operator = Context::operatorCode();
        $skills = $data['skills'] ?? [];
        if ($skills === []) {
            throw DomainException::ruleRejected('CONTRACTOR_SKILLS_REQUIRED', 'A contractor must carry at least one skill.');
        }
        $known = TechContractorSkill::query()->where('operator_code', $operator)->where('status', 'ACTIVE')->pluck('code')->all();
        if ($missing = array_diff($skills, $known)) {
            throw DomainException::ruleRejected('UNKNOWN_SKILL', 'Unknown skill code(s): '.implode(', ', $missing));
        }

        return Contractor::query()->create($data + ['operator_code' => $operator, 'status' => 'ACTIVE']);
    }

    /**
     * R-RLM-CFG-01-A-1: assign a contractor to a region for a subset of its skills.
     *
     * @param  array<int,string>  $skills
     */
    public function assignContractor(TechRegion $region, Contractor $contractor, array $skills): void
    {
        if ($skills === [] || array_diff($skills, $contractor->skills ?? [])) {
            throw DomainException::ruleRejected('ASSIGNMENT_SKILLS_INVALID', 'Assignment skills must be a non-empty subset of the contractor’s skills.');
        }
        DB::table('tech_region_contractor')->updateOrInsert(
            ['tech_region_id' => $region->tech_region_id, 'tech_contractor_id' => $contractor->contractor_id],
            ['operator_code' => $region->operator_code, 'skills' => json_encode(array_values($skills)), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** R-RLM-CFG-01-T-7: a TechRegion needs ≥1 active contractor assignment to activate. */
    public function activateRegion(TechRegion $region): TechRegion
    {
        $contractorIds = DB::table('tech_region_contractor')->where('tech_region_id', $region->tech_region_id)->pluck('tech_contractor_id');
        $active = Contractor::query()->whereIn('contractor_id', $contractorIds)->where('status', 'ACTIVE')->exists();
        if (! $active) {
            throw DomainException::ruleRejected('REGION_HAS_NO_CONTRACTOR', 'A TechRegion needs at least one active contractor assignment to activate.');
        }
        $region->update(['status' => 'ACTIVE', 'active' => true, 'has_been_active' => true]);

        return $region;
    }

    /** R-RLM-CFG-01-T-3: a TechRegion cannot retire while an active HomePass references it. */
    public function retireRegion(TechRegion $region): TechRegion
    {
        if ($this->regionHasActiveHomePass($region->tech_region_id)) {
            throw DomainException::ruleRejected('REGION_REFERENCED', 'A TechRegion with active HomePasses cannot be retired.');
        }
        $region->update(['status' => 'RETIRED', 'active' => false]);

        return $region;
    }

    /** R-RLM-CFG-01-C-4: a contractor assigned to a region with active HomePasses cannot retire. */
    public function retireContractor(Contractor $contractor): Contractor
    {
        $regions = DB::table('tech_region_contractor')->where('tech_contractor_id', $contractor->contractor_id)->pluck('tech_region_id');
        foreach ($regions as $regionId) {
            if ($this->regionHasActiveHomePass($regionId)) {
                throw DomainException::ruleRejected('CONTRACTOR_REFERENCED', 'The contractor still serves a region with active HomePasses; unassign first.');
            }
        }
        $contractor->update(['status' => 'RETIRED']);

        return $contractor;
    }

    private function regionHasActiveHomePass(string $regionId): bool
    {
        $homepassIds = DB::table('homepass_tech_region')->where('tech_region_ref', $regionId)->pluck('homepass_id');
        if ($homepassIds->isEmpty()) {
            return false;
        }
        $sellableOrActive = \Modules\Catalog\Models\HomePassStatusCode::query()
            ->where(fn ($q) => $q->where('is_sellable', true)->orWhere('is_active', true))->pluck('code');

        return DB::table('homepass')->whereIn('id', $homepassIds)->whereIn('status', $sellableOrActive)->exists();
    }
}
