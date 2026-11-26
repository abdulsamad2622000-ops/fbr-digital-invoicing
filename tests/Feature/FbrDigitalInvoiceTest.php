<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\FbrDigitalInvoiceService;
use App\Support\QrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FbrDigitalInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function setUpBusiness(array $overrides = []): BusinessSetting
    {
        return BusinessSetting::create(array_merge([
            'business_name'          => 'Test Traders',
            'address'                => 'Saddar, Karachi',
            'ntn'                    => '1234567-8',
            'seller_province'        => 'Sindh',
            'fbr_enabled'            => true,
            'fbr_environment'        => 'sandbox',
            'fbr_token'              => 'test-token-123',
            'fbr_prices_include_tax' => true,
        ], $overrides));
    }

    private function makeSale(array $customer = [], array $product = []): Sale
    {
        $user = User::factory()->create();
        $cust = Customer::create(array_merge([
            'name' => 'Ali Traders', 'address' => 'Clifton', 'cnic' => '42101-1234567-1',
            'registration_type' => 'Unregistered', 'province' => 'Sindh',
        ], $customer));
        $prod = Product::create(array_merge([
            'name' => 'Laptop', 'sale_price' => 1180, 'hs_code' => '8471.3020', 'fbr_tax_rate' => 18,
        ], $product));

        $sale = Sale::create([
            'memo_no' => 'S-1', 'type' => 'sale', 'customer_id' => $cust->id, 'user_id' => $user->id,
            'date' => '2026-11-26', 'subtotal' => 2360, 'discount' => 0, 'total' => 2360,
            'paid' => 2360, 'balance' => 0, 'payment_type' => 'cash',
        ]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $prod->id, 'qty' => 2, 'rate' => 1180, 'total' => 2360]);

        return $sale->fresh(['items.product', 'customer']);
    }

    public function test_payload_splits_tax_inclusive_price_and_uses_sandbox_scenario(): void
    {
        $this->setUpBusiness();
        $payload = (new FbrDigitalInvoiceService())->buildPayload($this->makeSale());

        $this->assertSame('Sale Invoice', $payload['invoiceType']);
        $this->assertSame('2026-11-26', $payload['invoiceDate']);
        $this->assertSame('1234567', $payload['sellerNTNCNIC']);
        $this->assertSame('4210112345671', $payload['buyerNTNCNIC']);
        $this->assertSame('Unregistered', $payload['buyerRegistrationType']);
        $this->assertSame('SN002', $payload['scenarioId']);

        $item = $payload['items'][0];
        $this->assertSame('8471.3020', $item['hsCode']);
        $this->assertSame('18%', $item['rate']);
        $this->assertSame(2000.0, $item['valueSalesExcludingST']);
        $this->assertSame(360.0, $item['salesTaxApplicable']);
        $this->assertSame(2360.0, $item['totalValues']);
        $this->assertSame(FbrDigitalInvoiceService::DEFAULT_UOM, $item['uoM']);
    }

    public function test_production_payload_has_no_scenario_id(): void
    {
        $this->setUpBusiness(['fbr_environment' => 'production']);
        $payload = (new FbrDigitalInvoiceService())->buildPayload($this->makeSale());

        $this->assertArrayNotHasKey('scenarioId', $payload);
    }

    public function test_successful_post_saves_fbr_invoice_number(): void
    {
        $this->setUpBusiness();
        $sale = $this->makeSale();

        Http::fake([
            'gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb' => Http::response([
                'invoiceNumber' => '1234567DI1795690000001',
                'dated' => '2026-11-26 15:00:00',
                'validationResponse' => ['statusCode' => '00', 'status' => 'Valid', 'error' => '', 'invoiceStatuses' => []],
            ]),
        ]);

        $result = (new FbrDigitalInvoiceService())->post($sale);

        $this->assertTrue($result['ok']);
        $sale->refresh();
        $this->assertSame('posted', $sale->fbr_status);
        $this->assertSame('1234567DI1795690000001', $sale->fbr_invoice_number);
        $this->assertSame('sandbox', $sale->fbr_environment);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer test-token-123')
            && $r['items'][0]['hsCode'] === '8471.3020');

        // A second post must not send the invoice to FBR again.
        (new FbrDigitalInvoiceService())->post($sale);
        Http::assertSentCount(1);
    }

    public function test_invalid_response_is_saved_as_failed_with_error(): void
    {
        $this->setUpBusiness();
        $sale = $this->makeSale();

        Http::fake([
            '*' => Http::response([
                'dated' => '2026-11-26 15:00:00',
                'validationResponse' => [
                    'statusCode' => '01', 'status' => 'Invalid', 'errorCode' => '', 'error' => '',
                    'invoiceStatuses' => [['itemSNo' => '1', 'statusCode' => '01', 'status' => 'Invalid', 'errorCode' => '0046', 'error' => 'Provide rate.']],
                ],
            ]),
        ]);

        $result = (new FbrDigitalInvoiceService())->post($sale);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('0046', $result['message']);
        $this->assertSame('failed', $sale->fresh()->fbr_status);
        $this->assertNull($sale->fresh()->fbr_invoice_number);
    }

    public function test_missing_hs_code_is_reported_without_calling_fbr(): void
    {
        $this->setUpBusiness();
        $sale = $this->makeSale([], ['hs_code' => null]);
        Http::fake();

        $result = (new FbrDigitalInvoiceService())->validate($sale);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HS code', $result['message']);
        Http::assertNothingSent();
    }

    public function test_unauthorized_token_gives_clear_message(): void
    {
        $this->setUpBusiness();
        Http::fake(['*' => Http::response(null, 401)]);

        $result = (new FbrDigitalInvoiceService())->validate($this->makeSale());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('401', $result['message']);
    }

    public function test_token_is_stored_encrypted(): void
    {
        $this->setUpBusiness();

        $raw = \DB::table('business_settings')->value('fbr_token');
        $this->assertNotSame('test-token-123', $raw);
        $this->assertSame('test-token-123', BusinessSetting::first()->fbr_token);
    }

    public function test_posted_invoice_views_show_fbr_number_and_qr(): void
    {
        $this->setUpBusiness();
        $sale = $this->makeSale();
        $sale->forceFill([
            'fbr_status' => 'posted', 'fbr_invoice_number' => '1234567DI1795690000001',
            'fbr_environment' => 'sandbox', 'fbr_posted_at' => now(),
        ])->save();
        $sale = $sale->fresh(['items.product', 'customer', 'salesperson']);

        foreach (['sales.invoice', 'sales.invoice-pdf'] as $view) {
            $html = view($view, compact('sale'))->render();
            $this->assertStringContainsString('1234567DI1795690000001', $html, $view);
            $this->assertStringContainsString('data:image/svg+xml;base64,', $html, $view);
            $this->assertStringContainsString('SANDBOX', $html, $view);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('sales.invoice-pdf', compact('sale'))->output();
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_unposted_invoice_shows_post_button_when_enabled(): void
    {
        $this->setUpBusiness();
        $sale = $this->makeSale()->load('salesperson');

        $html = view('sales.invoice', compact('sale'))->render();

        $this->assertStringContainsString('Post to FBR', $html);
        $this->assertStringNotContainsString('FBR Invoice No', $html);
    }

    public function test_qr_code_is_version_2_for_fbr_invoice_numbers(): void
    {
        $matrix = QrCode::matrix('1234567DI1795690000001');

        $this->assertCount(25, $matrix);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', QrCode::dataUri('1234567DI1795690000001'));
    }
}
