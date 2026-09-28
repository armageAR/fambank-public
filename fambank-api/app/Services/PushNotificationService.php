<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushNotificationService
{
    private WebPush $webPush;

    public function __construct()
    {
        $this->webPush = new WebPush(
            [
                'VAPID' => [
                    'subject'    => config('app.vapid_subject', 'mailto:admin@fambank.ar'),
                    'publicKey'  => config('app.vapid_public_key'),
                    'privateKey' => config('app.vapid_private_key'),
                ],
            ],
            [
                // urgency high: Android entrega la notificación aunque el teléfono
                // esté en modo ahorro de batería / doze. TTL 24h: si el dispositivo
                // está offline, el push service la retiene hasta un día.
                'TTL'     => 86400,
                'urgency' => 'high',
            ],
        );
    }

    /**
     * Envía una notificación push a todos los administradores.
     *
     * @return array{sent: int, failed: int, errors: list<string>}
     */
    public function notifyAdmins(string $title, string $body): array
    {
        $subs = PushSubscription::whereHas('user', fn ($q) => $q->where('role', UserRole::Admin))
            ->get();

        if ($subs->isEmpty()) {
            Log::warning('notifyAdmins: ningún admin tiene suscripción push registrada');

            return ['sent' => 0, 'failed' => 0, 'errors' => []];
        }

        return $this->send($subs, $title, $body);
    }

    /**
     * Envía una notificación push a un usuario específico (todos sus dispositivos).
     *
     * @return array{sent: int, failed: int, errors: list<string>}
     */
    public function notifyUser(User $user, string $title, string $body): array
    {
        return $this->send($user->pushSubscriptions()->get(), $title, $body);
    }

    /**
     * Encola y despacha las notificaciones, limpiando suscripciones expiradas.
     *
     * @param  Collection<int, PushSubscription>  $subs
     * @return array{sent: int, failed: int, errors: list<string>}
     */
    private function send(Collection $subs, string $title, string $body): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'errors' => []];

        if ($subs->isEmpty()) {
            return $result;
        }

        $payload = json_encode(['title' => $title, 'body' => $body]);

        foreach ($subs as $sub) {
            $this->webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => [
                        'p256dh' => $sub->p256dh,
                        'auth'   => $sub->auth,
                    ],
                ]),
                $payload
            );
        }

        foreach ($this->webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $result['sent']++;
                continue;
            }

            $result['failed']++;
            $result['errors'][] = $report->getReason();

            // Eliminar suscripciones inválidas (endpoint dado de baja por el browser)
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getRequest()->getUri()->__toString())->delete();
            }

            Log::warning('Push notification failed', [
                'reason'   => $report->getReason(),
                'endpoint' => substr($report->getRequest()->getUri()->__toString(), 0, 60),
            ]);
        }

        return $result;
    }
}
