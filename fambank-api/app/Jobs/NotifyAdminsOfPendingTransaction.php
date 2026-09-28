<?php

namespace App\Jobs;

use App\Services\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Notifica a los admins que hay una operación pendiente de aprobación.
 *
 * Encolado para que los HTTP requests a los push services no agreguen
 * latencia al request del member. Con QUEUE_CONNECTION=sync se ejecuta
 * inline (comportamiento actual); con database/redis requiere un worker.
 */
class NotifyAdminsOfPendingTransaction implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public int $transactionId,
    ) {}

    public function handle(): void
    {
        try {
            app(PushNotificationService::class)->notifyAdmins($this->title, $this->body);
        } catch (\Throwable $e) {
            Log::error('Push notification error', [
                'message'        => $e->getMessage(),
                'transaction_id' => $this->transactionId,
            ]);
        }
    }
}
