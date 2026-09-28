<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PushSubscriptionController extends Controller
{
    /**
     * Registra o actualiza la suscripción push del usuario autenticado.
     *
     * POST /api/push-subscriptions
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth'   => ['required', 'string'],
        ]);

        // El endpoint es la clave natural: única por browser/dispositivo.
        // Re-suscribirse actualiza la fila existente en vez de duplicarla.
        PushSubscription::updateOrCreate(
            ['endpoint' => $request->input('endpoint')],
            [
                'user_id' => $request->user()->id,
                'p256dh'  => $request->input('keys.p256dh'),
                'auth'    => $request->input('keys.auth'),
            ]
        );

        return response()->json(['message' => 'Suscripción registrada.']);
    }

    /**
     * Elimina la suscripción push del usuario autenticado.
     *
     * DELETE /api/push-subscriptions
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $request->input('endpoint'))
            ->delete();

        return response()->json(['message' => 'Suscripción eliminada.']);
    }

    /**
     * Envía una notificación de prueba al usuario autenticado.
     * Verifica end-to-end: config VAPID del servidor, suscripción en DB,
     * y entrega del push service hasta el dispositivo.
     *
     * POST /api/push-subscriptions/test
     *
     * @response 200 Notificación enviada
     * @response 422 Sin suscripción registrada o entrega rechazada
     * @response 503 VAPID mal configurado en el servidor
     */
    public function test(Request $request): JsonResponse
    {
        try {
            $push = app(PushNotificationService::class);
        } catch (\Throwable $e) {
            Log::error('Push test: VAPID mal configurado', ['message' => $e->getMessage()]);

            return response()->json([
                'message' => 'Las claves VAPID no están configuradas en el servidor.',
            ], 503);
        }

        $result = $push->notifyUser(
            $request->user(),
            '🔔 Notificación de prueba',
            'Si ves esto, las notificaciones funcionan correctamente.',
        );

        if ($result['sent'] === 0) {
            $message = $result['failed'] > 0
                ? 'El push service rechazó la entrega: ' . implode(' · ', array_unique($result['errors']))
                : 'No hay ninguna suscripción registrada en el servidor para tu usuario. Volvé a activar las notificaciones.';

            return response()->json(['message' => $message, 'data' => $result], 422);
        }

        return response()->json([
            'message' => "Notificación de prueba enviada ({$result['sent']} dispositivo" . ($result['sent'] !== 1 ? 's' : '') . ').',
            'data'    => $result,
        ]);
    }
}
