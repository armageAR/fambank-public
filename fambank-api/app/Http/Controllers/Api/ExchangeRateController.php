<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ExchangeRateUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;

class ExchangeRateController extends Controller
{
    public function __construct(private readonly ExchangeRateService $service) {}

    /**
     * Devuelve los tipos de cambio blue y oficial actuales desde Bluelytics.
     *
     * GET /api/exchange-rate
     *
     * @response 200 { "blue": { "buy": 1440.0, "sell": 1460.0 }, "oficial": { "buy": 1402.0, "sell": 1453.0 }, "fetched_at": "2026-06-09T10:00:00.000Z" }
     * @response 503 { "message": "No se pudo obtener el tipo de cambio blue. ..." }
     */
    public function show(): JsonResponse
    {
        try {
            $blue    = $this->service->getBlue();
            $oficial = $this->service->getOficial();

            return response()->json([
                'blue'       => ['buy' => $blue['buy'], 'sell' => $blue['sell']],
                'oficial'    => ['buy' => $oficial['buy'], 'sell' => $oficial['sell']],
                'fetched_at' => $blue['fetched_at']->toIso8601String(),
            ]);
        } catch (ExchangeRateUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}
