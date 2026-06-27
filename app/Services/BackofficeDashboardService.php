<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * FE-APP-01 §7.1 home-dashboard read model. Aggregates the operational backlog counts that
 * drive the role-aware widgets, plus an onboarding-pipeline breakdown and a recent-activity
 * feed. Each widget resolves in isolation (the Customer-360 pattern): a failing module blanks
 * only its tile, never the dashboard. Read-only cross-module BFF; owns no state.
 */
class BackofficeDashboardService
{
    /** @return array<string,mixed> */
    public function summary(?string $operator): array
    {
        return [
            'widgets' => $this->widgets($operator),
            'onboarding' => $this->guard(fn () => $this->onboardingPipeline($operator)),
            'recent' => $this->guard(fn () => $this->recentActivity($operator)),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function widgets(?string $operator): array
    {
        $counts = [
            'myTasks' => fn () => $this->count(\Modules\Workflow\Models\UserTask::class, $operator, fn ($q) => $q->whereIn('status', ['OPEN', 'CLAIMED'])),
            'kycPending' => fn () => $this->count(\Modules\Ilm\Models\Customer::class, $operator, fn ($q) => $q->where('kyc_status', 'PENDING')),
            'dunningRisk' => fn () => $this->count(\Modules\Billing\Dunning\Models\DunningState::class, $operator, fn ($q) => $q->where('current_level', '>', 0)),
            'woBacklog' => fn () => $this->count(\Modules\WorkOrder\Models\WorkOrder::class, $operator, fn ($q) => $q->whereIn('status', ['PENDING', 'ASSIGNED', 'IN_PROGRESS'])),
            'activationFailures' => fn () => $this->count(\Modules\Fulfillment\Models\FulfillmentOrderStep::class, $operator, fn ($q) => $q->where('status', 'FAILED')),
            'stockExceptions' => fn () => $this->count(\Modules\Osr\Swap\Models\EquipmentSwapRequest::class, $operator, fn ($q) => $q->whereIn('status', ['FAILED', 'COMPLETED_WITHOUT_RECOVERY'])),
        ];

        $out = [];
        foreach ($counts as $key => $resolver) {
            $out[$key] = $this->guard($resolver);
        }

        return $out;
    }

    /** Count rows of a model, operator-scoped if it has an operator_code column. */
    private function count(string $modelClass, ?string $operator, callable $filter): array
    {
        $q = $modelClass::query();
        if ($operator && \Illuminate\Support\Facades\Schema::hasColumn((new $modelClass)->getTable(), 'operator_code')) {
            $q->where('operator_code', $operator);
        }

        return ['count' => (int) $filter($q)->count()];
    }

    /** Onboarding pipeline: fulfillment orders grouped by status (feeds the visual tracker). */
    private function onboardingPipeline(?string $operator): array
    {
        $q = \Modules\Fulfillment\Models\FulfillmentOrder::query();
        if ($operator && \Illuminate\Support\Facades\Schema::hasColumn('fulfillment_order', 'operator_code')) {
            $q->where('operator_code', $operator);
        }

        return $q->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
    }

    /** Last domain events from the outbox — a live activity feed. */
    private function recentActivity(?string $operator): array
    {
        return \Illuminate\Support\Facades\DB::table('outbox_events')
            ->orderByDesc('created_at')->limit(10)
            ->get(['event_type', 'aggregate_type', 'created_at'])
            ->map(fn ($e) => ['type' => $e->event_type, 'aggregate' => $e->aggregate_type, 'at' => $e->created_at])->all();
    }

    /** @return array<string,mixed> */
    private function guard(callable $resolver): array
    {
        try {
            return ['available' => true, 'data' => $resolver()];
        } catch (\Throwable $e) {
            Log::warning('dashboard.widget_failed', ['error' => $e->getMessage()]);

            return ['available' => false];
        }
    }
}
