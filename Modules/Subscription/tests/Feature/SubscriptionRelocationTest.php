<?php

namespace Modules\Subscription\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Models\HomePass;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Modules\WorkOrder\Database\Seeders\WoSupportSeeder;
use Tests\TestCase;

class SubscriptionRelocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $this->seed(WoSupportSeeder::class); // wo-shifting flow for the relocation SHIFTING WO
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function drain(): void
    {
        Artisan::call('sophix:workflow:work', ['--once' => true]);
    }

    private function homePass(string $status = 'SERVICEABLE', string $tech = 'GPON'): string
    {
        $id = Id::make('hp');
        HomePass::query()->create([
            'id' => $id, 'operator_code' => 'WIK', 'address' => '123 St', 'technology' => $tech, 'status' => $status,
        ]);

        return $id;
    }

    private function activeSubscription(string $homepassId, string $tech = 'GPON'): string
    {
        // ensure source homepass exists with the source technology
        $sub = Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'a1',
            'operator_code' => 'WIK', 'homepass_id' => $homepassId, 'package_ref' => 'pkg_1',
            'status_code' => 'ACTIVE', 'currency' => 'KES',
        ]);

        return $sub->subscription_id;
    }

    public function test_relocation_moves_to_target_homepass(): void
    {
        $src = $this->homePass('SERVICEABLE', 'GPON');
        $tgt = $this->homePass('SERVICEABLE', 'GPON');
        $id = $this->activeSubscription($src);

        $this->postJson("/api/subscriptions/{$id}/relocate", ['targetHomepassId' => $tgt], ['Idempotency-Key' => 'rel-1'])
            ->assertStatus(202);
        $this->drain();

        $sub = Subscription::find($id);
        $this->assertSame($tgt, $sub->homepass_id);
        $this->assertSame($src, $sub->previous_homepass_id);
        $this->assertNull($sub->current_transition_type); // transient marker cleared on commit
        $this->assertSame('pkg_1', $sub->package_ref); // package unchanged
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionRelocated']);

        // The physical move raised a WO-01 SHIFTING work order (cross-module trigger),
        // carrying the operation reference as its source.
        $this->assertDatabaseHas('work_order', [
            'subscription_id' => $id, 'type' => 'SHIFTING', 'kind' => 'SHIFTING', 'source_type' => 'SUBSCRIPTION_OP',
        ]);
    }

    public function test_relocation_to_non_serviceable_is_rejected(): void
    {
        $src = $this->homePass('SERVICEABLE');
        $tgt = $this->homePass('RESERVED'); // not sellable
        $id = $this->activeSubscription($src);

        $this->postJson("/api/subscriptions/{$id}/relocate", ['targetHomepassId' => $tgt], ['Idempotency-Key' => 'rel-2'])
            ->assertStatus(202);
        $this->drain();

        $this->assertSame($src, Subscription::find($id)->homepass_id); // unchanged
    }

    public function test_migration_changes_homepass_and_technology(): void
    {
        $src = $this->homePass('SERVICEABLE', 'HFC');
        $tgt = $this->homePass('SERVICEABLE', 'GPON'); // technology changes
        $id = $this->activeSubscription($src);

        $this->postJson("/api/subscriptions/{$id}/migrate", ['targetHomepassId' => $tgt], ['Idempotency-Key' => 'mig-1'])
            ->assertStatus(202);
        $this->drain();

        $sub = Subscription::find($id);
        $this->assertSame($tgt, $sub->homepass_id);
        $this->assertNull($sub->current_transition_type); // transient marker cleared on commit
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionMigrated']);
    }

    public function test_migration_without_technology_change_is_rejected(): void
    {
        $src = $this->homePass('SERVICEABLE', 'GPON');
        $tgt = $this->homePass('SERVICEABLE', 'GPON'); // same tech -> not a migration
        $id = $this->activeSubscription($src);

        $this->postJson("/api/subscriptions/{$id}/migrate", ['targetHomepassId' => $tgt], ['Idempotency-Key' => 'mig-2'])
            ->assertStatus(202);
        $this->drain();

        $this->assertSame($src, Subscription::find($id)->homepass_id);
    }
}
