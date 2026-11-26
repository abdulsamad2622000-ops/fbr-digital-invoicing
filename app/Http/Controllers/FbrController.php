<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\FbrDigitalInvoiceService;
use Illuminate\Http\Request;

class FbrController extends Controller
{
    public function validateSale(Request $request, Sale $sale, FbrDigitalInvoiceService $fbr)
    {
        $result = $fbr->validate($sale);

        return $this->respond($request, $result);
    }

    public function postSale(Request $request, Sale $sale, FbrDigitalInvoiceService $fbr)
    {
        $result = $fbr->post($sale);

        return $this->respond($request, $result);
    }

    public function testConnection(Request $request, FbrDigitalInvoiceService $fbr)
    {
        return $this->respond($request, $fbr->testConnection());
    }

    private function respond(Request $request, array $result)
    {
        unset($result['response']);

        if ($request->expectsJson()) {
            return response()->json($result, $result['ok'] ? 200 : 422);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
