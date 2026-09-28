<?php

namespace Tests\Unit\Services;

use App\Exceptions\ExchangeRateUnavailableException;
use App\Services\ExchangeRateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    private ExchangeRateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExchangeRateService();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_blue_buy_sell_and_fetched_at_on_success(): void
    {
        Http::fake([
            '*' => Http::response($this->bluelyticsPayload(), 200),
        ]);

        $result = $this->service->getBlue();

        $this->assertSame(1440.0, $result['buy']);
        $this->assertSame(1460.0, $result['sell']);
        $this->assertInstanceOf(Carbon::class, $result['fetched_at']);
        $this->assertEquals('2026-06-09T10:00:00+00:00', $result['fetched_at']->toIso8601String());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_floats_even_when_api_sends_integers(): void
    {
        $payload = $this->bluelyticsPayload();
        $payload['blue']['value_buy']  = 1180;
        $payload['blue']['value_sell'] = 1200;

        Http::fake(['*' => Http::response($payload, 200)]);

        $result = $this->service->getBlue();

        $this->assertIsFloat($result['buy']);
        $this->assertIsFloat($result['sell']);
    }

    // -------------------------------------------------------------------------
    // Failures — should throw ExchangeRateUnavailableException
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_api_returns_server_error(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->expectException(ExchangeRateUnavailableException::class);

        $this->service->getBlue();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_api_returns_404(): void
    {
        Http::fake(['*' => Http::response(null, 404)]);

        $this->expectException(ExchangeRateUnavailableException::class);

        $this->service->getBlue();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_blue_key_is_missing_from_response(): void
    {
        Http::fake(['*' => Http::response(['oficial' => []], 200)]);

        $this->expectException(ExchangeRateUnavailableException::class);

        $this->service->getBlue();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_value_buy_is_missing(): void
    {
        $payload = $this->bluelyticsPayload();
        unset($payload['blue']['value_buy']);

        Http::fake(['*' => Http::response($payload, 200)]);

        $this->expectException(ExchangeRateUnavailableException::class);

        $this->service->getBlue();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_value_sell_is_missing(): void
    {
        $payload = $this->bluelyticsPayload();
        unset($payload['blue']['value_sell']);

        Http::fake(['*' => Http::response($payload, 200)]);

        $this->expectException(ExchangeRateUnavailableException::class);

        $this->service->getBlue();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_connection_fails(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $this->expectException(ExchangeRateUnavailableException::class);

        $this->service->getBlue();
    }

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_caches_the_rate_and_does_not_hit_the_api_again(): void
    {
        Http::fake(['*' => Http::response($this->bluelyticsPayload(), 200)]);

        $first  = $this->service->getBlue();
        $second = $this->service->getBlue();

        Http::assertSentCount(1);
        $this->assertSame($first['buy'], $second['buy']);
        $this->assertSame($first['sell'], $second['sell']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_cache_failures(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(null, 500)
                ->push($this->bluelyticsPayload(), 200),
        ]);

        try {
            $this->service->getBlue();
            $this->fail('Expected ExchangeRateUnavailableException was not thrown');
        } catch (ExchangeRateUnavailableException) {
        }

        // El fallo no quedó cacheado: el siguiente request reintenta y funciona
        $result = $this->service->getBlue();
        $this->assertSame(1440.0, $result['buy']);
    }

    // -------------------------------------------------------------------------
    // Exception message
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function exception_message_contains_descriptive_text(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        try {
            $this->service->getBlue();
            $this->fail('Expected ExchangeRateUnavailableException was not thrown');
        } catch (ExchangeRateUnavailableException $e) {
            $this->assertStringContainsString('tipo de cambio blue', $e->getMessage());
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function exception_message_includes_the_failure_reason(): void
    {
        Http::fake(['*' => Http::response(null, 503)]);

        try {
            $this->service->getBlue();
            $this->fail('Expected ExchangeRateUnavailableException was not thrown');
        } catch (ExchangeRateUnavailableException $e) {
            $this->assertStringContainsString('Detalle:', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_logs_an_error_before_throwing(): void
    {
        Log::spy();

        Http::fake(['*' => Http::response(null, 500)]);

        try {
            $this->service->getBlue();
        } catch (ExchangeRateUnavailableException) {
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Bluelytics'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function bluelyticsPayload(): array
    {
        return [
            'oficial'      => ['value_avg' => 1438.0, 'value_buy' => 1412.0, 'value_sell' => 1464.0],
            'blue'         => ['value_avg' => 1450.0, 'value_buy' => 1440.0, 'value_sell' => 1460.0],
            'oficial_euro' => ['value_avg' => 1563.0, 'value_buy' => 1535.0, 'value_sell' => 1591.0],
            'blue_euro'    => ['value_avg' => 1576.0, 'value_buy' => 1565.0, 'value_sell' => 1587.0],
            'last_update'  => '2026-06-09T10:00:00.000Z',
        ];
    }
}
