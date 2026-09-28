<?php

namespace App\Services;

use App\Exceptions\ExchangeRateUnavailableException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    private const API_URL = 'https://api.bluelytics.com.ar/v2/latest';
    private const TIMEOUT  = 5;

    private const CACHE_KEY_BLUE    = 'exchange_rate.blue';
    private const CACHE_KEY_OFICIAL = 'exchange_rate.oficial';
    private const CACHE_TTL = 300; // 5 minutos, mismo intervalo de refresco que el frontend

    /**
     * Tipo de cambio blue desde Bluelytics, cacheado 5 minutos.
     * Los fallos no se cachean: el próximo request reintenta contra la API.
     *
     * @return array{buy: float, sell: float, fetched_at: Carbon}
     *
     * @throws ExchangeRateUnavailableException si la API no responde o devuelve datos inválidos
     */
    public function getBlue(): array
    {
        return Cache::remember(self::CACHE_KEY_BLUE, self::CACHE_TTL, fn () => $this->fetchRate('blue'));
    }

    /**
     * Tipo de cambio oficial desde Bluelytics, cacheado 5 minutos.
     * Los fallos no se cachean: el próximo request reintenta contra la API.
     *
     * @return array{buy: float, sell: float, fetched_at: Carbon}
     *
     * @throws ExchangeRateUnavailableException si la API no responde o devuelve datos inválidos
     */
    public function getOficial(): array
    {
        return Cache::remember(self::CACHE_KEY_OFICIAL, self::CACHE_TTL, fn () => $this->fetchRate('oficial'));
    }

    /**
     * @return array{buy: float, sell: float, fetched_at: Carbon}
     *
     * @throws ExchangeRateUnavailableException
     */
    private function fetchRate(string $type): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)->get(self::API_URL);

            if ($response->failed()) {
                throw new \RuntimeException("HTTP {$response->status()}");
            }

            $rate = $response->json($type);

            if (! isset($rate['value_buy'], $rate['value_sell'])) {
                throw new \RuntimeException('La respuesta no contiene los campos esperados');
            }

            return [
                'buy'        => (float) $rate['value_buy'],
                'sell'       => (float) $rate['value_sell'],
                'fetched_at' => Carbon::parse($response->json('last_update')),
            ];
        } catch (ExchangeRateUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('ExchangeRateService: fallo al consultar Bluelytics', [
                'message' => $e->getMessage(),
                'url'     => self::API_URL,
            ]);

            throw new ExchangeRateUnavailableException($e->getMessage(), $type);
        }
    }
}
