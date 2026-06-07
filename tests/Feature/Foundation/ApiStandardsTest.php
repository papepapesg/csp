<?php

namespace Tests\Feature\Foundation;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Events\Outbox\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Locks in the cross-cutting DD_API-00 conventions provided by the foundation.
 */
class ApiStandardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_up_and_correlation_id(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'UP')
            ->assertJsonPath('service', config('sophix.name'));

        $this->assertNotEmpty($response->headers->get('X-Correlation-Id'));
    }

    public function test_correlation_id_is_preserved_when_supplied(): void
    {
        $response = $this->getJson('/api/health', ['X-Correlation-Id' => 'corr_test_123']);

        $response->assertOk();
        $this->assertSame('corr_test_123', $response->headers->get('X-Correlation-Id'));
    }

    public function test_unknown_api_route_returns_standard_error_model(): void
    {
        $response = $this->getJson('/api/does-not-exist');

        $response->assertStatus(404)
            ->assertJsonStructure(['errorCode', 'message', 'correlationId', 'retryable']);
    }

    public function test_idempotency_replays_stored_response(): void
    {
        Route::middleware(['api', 'idempotency'])->post('/api/_test/echo', function () {
            return response()->json(['nonce' => uniqid('', true)], 201);
        });

        $headers = ['Idempotency-Key' => 'idem-key-1'];

        $first = $this->postJson('/api/_test/echo', ['a' => 1], $headers);
        $first->assertCreated();

        $second = $this->postJson('/api/_test/echo', ['a' => 1], $headers);
        $second->assertCreated();

        // Same key + same body => identical replayed payload.
        $this->assertSame($first->json('nonce'), $second->json('nonce'));
        $this->assertSame('true', $second->headers->get('Idempotent-Replay'));
    }

    public function test_idempotency_conflict_on_different_body(): void
    {
        Route::middleware(['api', 'idempotency'])->post('/api/_test/echo2', fn () => response()->json(['ok' => true], 201));

        $headers = ['Idempotency-Key' => 'idem-key-2'];
        $this->postJson('/api/_test/echo2', ['a' => 1], $headers)->assertCreated();
        $this->postJson('/api/_test/echo2', ['a' => 2], $headers)
            ->assertStatus(409)
            ->assertJsonPath('errorCode', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_event_bus_writes_to_outbox(): void
    {
        app(EventBus::class)->publish(new DomainEvent(
            type: 'TestHappened',
            topic: 'test.events',
            payload: ['hello' => 'world'],
            aggregateType: 'Test',
            aggregateId: 'test_1',
        ));

        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertSame('TestHappened', OutboxEvent::first()->event_type);
        $this->assertNull(OutboxEvent::first()->published_at);
    }
}
