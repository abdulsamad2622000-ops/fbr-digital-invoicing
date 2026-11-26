<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Sale;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * FBR Digital Invoicing (PRAL DI API v1.12).
 *
 * Builds the invoice payload from a Sale, validates or posts it to PRAL, and
 * stores the FBR invoice number (IRN) on the sale. The same URLs serve sandbox
 * and production; the token decides where a request goes, and only the
 * "_sb" endpoints are used while the business is set to sandbox.
 */
class FbrDigitalInvoiceService
{
    public const BASE_URL = 'https://gw.fbr.gov.pk';

    public const DEFAULT_SALE_TYPE = 'Goods at standard rate (default)';
    public const DEFAULT_UOM = 'Numbers, pieces, units';
    public const DEFAULT_TAX_RATE = 18.0;

    public const PROVINCES = [
        'Sindh', 'Punjab', 'Khyber Pakhtunkhwa', 'Balochistan',
        'Islamabad Capital Territory', 'Gilgit-Baltistan', 'Azad Jammu and Kashmir',
    ];

    public function __construct(private ?BusinessSetting $setting = null)
    {
        $this->setting ??= BusinessSetting::first();
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->setting?->fbr_enabled) && !empty($this->setting?->fbr_token);
    }

    public function isSandbox(): bool
    {
        return ($this->setting?->fbr_environment ?? 'sandbox') !== 'production';
    }

    /**
     * Checks that everything FBR needs is present before calling the API.
     *
     * @return string[] human-readable problems (empty when ready)
     */
    public function missingData(Sale $sale): array
    {
        $problems = [];
        $s = $this->setting;

        if (!$s?->fbr_token) {
            $problems[] = 'FBR token settings mein nahi laga.';
        }
        if (!$s?->ntn) {
            $problems[] = 'Business NTN settings mein nahi hai.';
        }
        if (!$s?->seller_province) {
            $problems[] = 'Business ka province settings mein select karein.';
        }
        if ($sale->type !== 'sale') {
            $problems[] = 'Sirf sale invoice FBR ko bheji ja sakti hai.';
        }
        if ($sale->items->isEmpty()) {
            $problems[] = 'Invoice mein koi item nahi.';
        }
        foreach ($sale->items as $item) {
            if (!$item->product?->hs_code) {
                $problems[] = 'Product "' . ($item->product?->name ?? $item->description) . '" ka HS code nahi laga.';
            }
        }

        $customer = $sale->customer;
        if ($customer && $customer->registration_type === 'Registered' && !$this->buyerId($customer)) {
            $problems[] = 'Registered customer ka NTN/CNIC nahi hai.';
        }

        return array_values(array_unique($problems));
    }

    /**
     * Builds the PRAL request body for a sale.
     */
    public function buildPayload(Sale $sale): array
    {
        $sale->loadMissing(['items.product', 'customer']);
        $s = $this->setting;
        $customer = $sale->customer;

        $registered = $customer && $customer->registration_type === 'Registered';
        $lineTotal = (float) $sale->items->sum('total');
        $saleDiscount = (float) ($sale->discount ?? 0);
        $includesTax = (bool) ($s?->fbr_prices_include_tax ?? true);

        $items = [];
        foreach ($sale->items as $item) {
            $product = $item->product;
            $rate = (float) ($product?->fbr_tax_rate ?? self::DEFAULT_TAX_RATE);
            $gross = (float) $item->total;
            $discountShare = $lineTotal > 0 ? round($saleDiscount * $gross / $lineTotal, 2) : 0.0;
            $net = max($gross - $discountShare, 0);

            $excl = $includesTax ? $net / (1 + $rate / 100) : $net;
            $excl = round($excl, 2);
            $tax = round($excl * $rate / 100, 2);

            $items[] = [
                'hsCode'                          => $product?->hs_code,
                'productDescription'              => $product?->name ?? $item->description ?? 'Item',
                'rate'                            => $this->formatRate($rate),
                'uoM'                             => $product?->fbr_uom ?: self::DEFAULT_UOM,
                'quantity'                        => (float) $item->qty,
                'totalValues'                     => round($excl + $tax, 2),
                'valueSalesExcludingST'           => $excl,
                'fixedNotifiedValueOrRetailPrice' => 0,
                'salesTaxApplicable'              => $tax,
                'salesTaxWithheldAtSource'        => 0,
                'extraTax'                        => 0,
                'furtherTax'                      => 0,
                'sroScheduleNo'                   => '',
                'fedPayable'                      => 0,
                'discount'                        => $discountShare,
                'saleType'                        => $product?->fbr_sale_type ?: self::DEFAULT_SALE_TYPE,
                'sroItemSerialNo'                 => '',
            ];
        }

        $payload = [
            'invoiceType'           => 'Sale Invoice',
            'invoiceDate'           => date('Y-m-d', strtotime((string) $sale->date)),
            'sellerNTNCNIC'         => $this->digits($s?->ntn),
            'sellerBusinessName'    => $s?->business_name,
            'sellerProvince'        => $s?->seller_province,
            'sellerAddress'         => $s?->address,
            'buyerNTNCNIC'          => $customer ? $this->buyerId($customer) : '',
            'buyerBusinessName'     => $customer?->name ?? 'Walk-in Customer',
            'buyerProvince'         => $customer?->province ?: $s?->seller_province,
            'buyerAddress'          => $customer?->address ?: '-',
            'buyerRegistrationType' => $registered ? 'Registered' : 'Unregistered',
            'invoiceRefNo'          => '',
            'items'                 => $items,
        ];

        if ($this->isSandbox()) {
            $payload['scenarioId'] = $s?->fbr_scenario_id ?: ($registered ? 'SN001' : 'SN002');
        }

        return $payload;
    }

    /**
     * Validates the invoice with FBR without saving it there.
     */
    public function validate(Sale $sale): array
    {
        return $this->send($sale, 'validateinvoicedata');
    }

    /**
     * Posts the invoice to FBR and stores the result on the sale.
     */
    public function post(Sale $sale): array
    {
        if ($sale->isPostedToFbr()) {
            return ['ok' => true, 'invoiceNumber' => $sale->fbr_invoice_number, 'message' => 'Invoice pehle hi FBR par post ho chuki hai.'];
        }

        $result = $this->send($sale, 'postinvoicedata');

        $sale->forceFill([
            'fbr_status'         => $result['ok'] ? 'posted' : 'failed',
            'fbr_invoice_number' => $result['ok'] ? $result['invoiceNumber'] : null,
            'fbr_environment'    => $this->isSandbox() ? 'sandbox' : 'production',
            'fbr_posted_at'      => $result['ok'] ? now() : null,
            'fbr_error'          => $result['ok'] ? null : $result['message'],
            'fbr_response'       => $result['response'] ?? null,
        ])->save();

        return $result;
    }

    /**
     * Checks the token by calling a reference API (no invoice is sent).
     */
    public function testConnection(): array
    {
        if (!$this->setting?->fbr_token) {
            return ['ok' => false, 'message' => 'Pehle FBR token save karein.'];
        }

        try {
            $response = $this->http()->get(self::BASE_URL . '/pdi/v1/provinces');
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'FBR server se connection nahi hua: ' . $e->getMessage()];
        }

        if ($response->status() === 401) {
            return ['ok' => false, 'message' => 'Token ghalat hai ya is server ka IP FBR mein whitelist nahi.'];
        }

        return $response->successful()
            ? ['ok' => true, 'message' => 'FBR se connection theek hai.']
            : ['ok' => false, 'message' => 'FBR ne HTTP ' . $response->status() . ' diya.'];
    }

    private function send(Sale $sale, string $endpoint): array
    {
        $sale->loadMissing(['items.product', 'customer']);

        $missing = $this->missingData($sale);
        if ($missing) {
            return ['ok' => false, 'message' => implode(' ', $missing), 'response' => null];
        }

        $url = self::BASE_URL . '/di_data/v1/di/' . $endpoint . ($this->isSandbox() ? '_sb' : '');
        $payload = $this->buildPayload($sale);

        try {
            $response = $this->http()->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('FBR DI connection failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'FBR server se connection nahi hua. Internet ya IP whitelist check karein.', 'response' => null];
        }

        $body = $response->json() ?? [];

        if ($response->status() === 401) {
            return ['ok' => false, 'message' => 'FBR ne token reject kiya (401). Token ya IP whitelist check karein.', 'response' => $body];
        }
        if (!$response->successful()) {
            return ['ok' => false, 'message' => 'FBR server error (HTTP ' . $response->status() . ').', 'response' => $body];
        }

        $validation = $body['validationResponse'] ?? [];
        $valid = ($validation['statusCode'] ?? null) === '00';

        if (!$valid) {
            return ['ok' => false, 'message' => $this->errorMessage($validation), 'response' => $body];
        }

        if ($endpoint === 'postinvoicedata' && empty($body['invoiceNumber'])) {
            return ['ok' => false, 'message' => 'FBR ne invoice number wapas nahi diya.', 'response' => $body];
        }

        return [
            'ok'            => true,
            'invoiceNumber' => $body['invoiceNumber'] ?? null,
            'message'       => $endpoint === 'postinvoicedata' ? 'Invoice FBR par post ho gayi.' : 'Invoice FBR ke mutabiq theek hai.',
            'response'      => $body,
        ];
    }

    private function errorMessage(array $validation): string
    {
        $parts = [];
        if (!empty($validation['error'])) {
            $parts[] = trim(($validation['errorCode'] ?? '') . ' ' . $validation['error']);
        }
        foreach ($validation['invoiceStatuses'] ?? [] as $status) {
            if (($status['statusCode'] ?? '00') !== '00' && !empty($status['error'])) {
                $parts[] = 'Item ' . ($status['itemSNo'] ?? '?') . ': ' . trim(($status['errorCode'] ?? '') . ' ' . $status['error']);
            }
        }

        return $parts ? implode(' | ', $parts) : 'FBR ne invoice invalid batayi.';
    }

    private function http()
    {
        $token = $this->setting?->fbr_token;
        if (!$token) {
            throw new RuntimeException('FBR token missing.');
        }

        return Http::withToken($token)->acceptJson()->asJson()->timeout(30)->connectTimeout(10);
    }

    private function buyerId($customer): string
    {
        return $this->digits($customer->ntn ?: $customer->cnic);
    }

    /**
     * FBR expects a 7-digit NTN or a 13-digit CNIC, digits only.
     * "1234567-8" (NTN with check digit) becomes "1234567".
     */
    private function digits(?string $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{7})-\d$/', $value, $m)) {
            return $m[1];
        }

        return preg_replace('/\D/', '', $value);
    }

    private function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
    }
}
