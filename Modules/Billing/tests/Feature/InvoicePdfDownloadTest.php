<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\InvoiceService;
use Modules\Notification\Models\RenderedArtifact;
use Modules\Notification\Models\Template;
use Tests\TestCase;

/**
 * Generation is decoupled from sending: an operator can render + download an invoice PDF
 * (to print and hand over) without any notification being sent. The PDF is a first-class
 * rendered_artifact, produced by the NOT-01 document layer via the EM Foundation contract.
 */
class InvoicePdfDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('local');
        Context::setOperatorCode('WIK');
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function seedTemplate(): void
    {
        Template::query()->create([
            'operator_code' => 'WIK', 'template_format' => 'PDF',
            'template_purpose_code' => 'INVOICE_DOCUMENT', 'locale' => 'en',
            'version' => 1, 'status' => Template::STATUS_ACTIVE, 'engine_type' => 'HTML_TO_PDF',
            'template_payload' => 'Invoice {{ invoice.number }} total {{ invoice.total }} {{ invoice.currency }}',
        ]);
    }

    private function invoice(): Invoice
    {
        return app(InvoiceService::class)->generate(
            ['account_id' => 'acc_pdf', 'customer_id' => 'cust_pdf'],
            [['description' => 'Monthly fee', 'quantity' => 1, 'unit_price' => 2500]],
        );
    }

    public function test_operator_downloads_invoice_pdf_without_sending(): void
    {
        $this->seedTemplate();
        $invoice = $this->invoice();

        $res = $this->get("/api/invoices/{$invoice->invoice_id}/pdf");

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString($invoice->legal_invoice_number, (string) $res->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $res->getContent());

        // The artifact is a first-class, stored thing...
        $this->assertDatabaseHas('rendered_artifact', [
            'entity_type' => 'INVOICE', 'entity_id' => $invoice->invoice_id, 'format' => 'PDF', 'status' => 'RENDERED',
        ]);
        // ...and NOTHING was sent — generation is decoupled from delivery.
        $this->assertDatabaseCount('notification_log', 0);
    }

    public function test_second_download_reuses_the_cached_artifact(): void
    {
        $this->seedTemplate();
        $invoice = $this->invoice();

        $this->get("/api/invoices/{$invoice->invoice_id}/pdf")->assertOk();
        $this->get("/api/invoices/{$invoice->invoice_id}/pdf")->assertOk();

        // Cached by (entity, format, locale): one row, not re-rendered on every fetch.
        $this->assertSame(1, RenderedArtifact::query()
            ->where('entity_id', $invoice->invoice_id)->where('format', 'PDF')->count());
    }

    public function test_missing_template_is_a_clean_error_not_a_send_failure(): void
    {
        $invoice = $this->invoice(); // no template seeded

        $this->get("/api/invoices/{$invoice->invoice_id}/pdf")
            ->assertNotFound()
            ->assertJsonPath('errorCode', 'DOCUMENT_TEMPLATE_NOT_FOUND');

        // The miss surfaces to the caller; it is NOT swallowed into the send pipeline's retry queue.
        $this->assertDatabaseCount('render_failure_queue', 0);
    }
}
