<?php

namespace Modules\Notification\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Notification\Database\Seeders\TemplateCatalogSeeder;
use Modules\Notification\Services\NotificationService;
use Tests\TestCase;

/**
 * NOT-01 template studio: per-channel templates render with {{variables}}, the same
 * code differs by channel, and notifications resolve their body from the template.
 */
class TemplateStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(TemplateCatalogSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_same_code_renders_differently_per_channel(): void
    {
        $sms = $this->postJson('/api/notification-templates/preview', [
            'template_code' => 'SUBSCRIPTION_ACTIVATED', 'channel' => 'SMS', 'variables' => ['customerName' => 'Sarah'],
        ])->assertOk()->json();
        $this->assertStringContainsString('Sarah', $sms['body']);
        $this->assertNull($sms['subject']);

        $email = $this->postJson('/api/notification-templates/preview', [
            'template_code' => 'SUBSCRIPTION_ACTIVATED', 'channel' => 'EMAIL', 'variables' => ['customerName' => 'Sarah', 'subscriptionId' => 'sub_9'],
        ])->assertOk()->json();
        $this->assertSame('Your service is active', $email['subject']);
        $this->assertStringContainsString('sub_9', $email['body']);
        $this->assertNotSame($sms['body'], $email['body']);
    }

    public function test_notification_send_resolves_body_from_template(): void
    {
        $n = app(NotificationService::class)->send([
            'channel' => 'SMS', 'recipient' => '+254700000000', 'template_code' => 'INVOICE_ISSUED',
            'payload' => ['invoiceNumber' => 'Inv-WIK-2026-000001', 'currency' => 'KES', 'amount' => '2500.00', 'dueDate' => '2026-07-01'],
        ]);

        $this->assertStringContainsString('Inv-WIK-2026-000001', $n->body);
        $this->assertStringContainsString('2500.00', $n->body);
        $this->assertSame('SENT', $n->status);
    }

    public function test_studio_create_activate_and_preview_a_new_template(): void
    {
        $created = $this->postJson('/api/notification-templates', [
            'template_code' => 'WELCOME_BACK', 'channel' => 'WHATSAPP',
            'body' => 'Welcome back {{name}}!', 'variables' => ['name'],
        ])->assertCreated()->assertJsonPath('status', 'DRAFT')->json();

        // DRAFT is not resolved until activated.
        $this->postJson('/api/notification-templates/preview', ['template_code' => 'WELCOME_BACK', 'channel' => 'WHATSAPP', 'variables' => ['name' => 'Ada']])
            ->assertOk()->assertJsonPath('body', null);

        $this->postJson("/api/notification-templates/{$created['template_id']}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');

        $this->postJson('/api/notification-templates/preview', ['template_code' => 'WELCOME_BACK', 'channel' => 'WHATSAPP', 'variables' => ['name' => 'Ada']])
            ->assertOk()->assertJsonPath('body', 'Welcome back Ada!');
    }

    public function test_invoice_template_layout_is_seeded(): void
    {
        $this->getJson('/api/invoice-templates')->assertOk()
            ->assertJsonPath('items.0.code', 'STANDARD');
    }
}
