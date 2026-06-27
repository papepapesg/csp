<?php

namespace Modules\Catalog\Network\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Network\Models\HomePass;
use Modules\Workforce\Models\Contractor;

/**
 * RLM-CFG-01 §network_path — the topology engine. A HomePass's ordered node chain drives
 * two derived reads computed at save time and stored: services_supported (R-RLM-CFG-01-H-12)
 * and service_management_endpoints (R-RLM-CFG-01-H-11). Plus contractor routing: "which
 * contractors can do skill X at this HomePass" via the region→contractor join's per-region skills.
 */
class HomePassTopologyService
{
    /** Deployment-configurable node-type → service-family mapping (default; R-PLM-CFG-01-6). */
    private const NODE_SERVICE_FAMILIES = [
        'OLT' => ['data', 'iptv_multicast'],
        'VOIPSWITCH' => ['voice'],
        'HEADEND' => ['iptv_multicast'],
        'NMS' => ['monitoring'],
    ];

    /** Service family → customer-facing services_supported code (monitoring is not customer-facing). */
    private const FAMILY_SUPPORTED = ['data' => 'DATA', 'voice' => 'VOICE', 'iptv_multicast' => 'IPTV_MULTICAST'];

    public function __construct(private readonly EventBus $events) {}

    /**
     * Set the HomePass network path and re-derive services_supported + service_management_endpoints.
     *
     * @param  array<string,mixed>  $path  {captureMode, nodes:[{type,code,role,port}]}
     */
    public function setNetworkPath(HomePass $homepass, array $path): HomePass
    {
        $derived = $this->derive($path);

        return DB::transaction(function () use ($homepass, $path, $derived) {
            $homepass->update([
                'network_path' => $path,
                'services_supported' => $derived['servicesSupported'],
                'service_management_endpoints' => $derived['endpoints'],
            ]);
            $this->events->publish(new DomainEvent(
                type: CatalogEvents::HOMEPASS_STATUS_CHANGED, // reuse topic; carries the derived service set
                topic: CatalogEvents::TOPIC,
                payload: ['homepassId' => $homepass->id, 'servicesSupported' => $derived['servicesSupported']],
                aggregateType: 'HomePass',
                aggregateId: $homepass->id,
            ));

            return $homepass->refresh();
        });
    }

    /**
     * @param  array<string,mixed>  $path
     * @return array{servicesSupported:array<int,string>, endpoints:array<string,array{nodeCode:?string,port:?string}>}
     */
    public function derive(array $path): array
    {
        $supported = [];
        $endpoints = [];
        foreach ($path['nodes'] ?? [] as $node) {
            $families = self::NODE_SERVICE_FAMILIES[$node['type'] ?? ''] ?? [];
            foreach ($families as $family) {
                if (isset(self::FAMILY_SUPPORTED[$family])) {
                    $supported[self::FAMILY_SUPPORTED[$family]] = true;
                }
                // service_management_endpoints: only nodes the gateways must address.
                if (($node['role'] ?? null) === 'service_management') {
                    $endpoints[$family] = ['nodeCode' => $node['code'] ?? null, 'port' => $node['port'] ?? null];
                }
            }
        }

        return ['servicesSupported' => array_keys($supported), 'endpoints' => $endpoints];
    }

    /**
     * RLM-CFG-01 §1 / R-RLM-CFG-01-A-1 — contractors eligible to perform a skill at this HomePass:
     * walk HomePass → TechRegions → region-contractor assignments whose per-region skills include the
     * skill → ACTIVE contractors. The routing filter is the contractor-level certification, not per-staff.
     *
     * @return array<int,array<string,mixed>>
     */
    public function eligibleContractors(HomePass $homepass, string $skill): array
    {
        $regions = DB::table('homepass_tech_region')->where('homepass_id', $homepass->id)->pluck('tech_region_ref');

        $assignments = DB::table('tech_region_contractor')
            ->whereIn('tech_region_id', $regions)
            ->whereJsonContains('skills', $skill)
            ->pluck('tech_contractor_id')->unique();

        return Contractor::query()
            ->whereIn('contractor_id', $assignments)
            ->where('status', 'ACTIVE')
            ->get(['contractor_id', 'code', 'name', 'skills'])->toArray();
    }
}
