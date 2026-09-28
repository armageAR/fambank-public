<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    /**
     * Lista todas las transacciones pendientes para revisión del admin.
     *
     * GET /api/admin/transactions
     */
    public function index(): JsonResponse
    {
        $transactions = Transaction::pending()
            ->with(['user', 'reviewer'])
            ->latest()
            ->get();

        return response()->json([
            'data' => TransactionResource::collection($transactions),
        ]);
    }

    /**
     * Confirma una transacción pendiente. El admin puede ajustar el tipo de cambio.
     * Si es depósito, acredita el saldo ahora.
     * Si es retiro, el saldo ya fue descontado al crearse.
     *
     * PUT /api/admin/transactions/{transaction}/confirm
     */
    public function confirm(ConfirmTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        DB::transaction(function () use ($request, $transaction) {
            // Re-leer con lock: el check de estado y el movimiento de saldo deben ser
            // atómicos para que confirm/reject concurrentes no dupliquen el efecto.
            $tx = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($tx->status !== TransactionStatus::Pending) {
                abort(422, 'Solo se pueden confirmar transacciones pendientes.');
            }

            $exchangeRate = $request->has('exchange_rate')
                ? $request->input('exchange_rate')
                : $tx->exchange_rate;

            // amount_ars es inmutable: es lo que el usuario depositó/retiró en pesos.
            // Si el admin cambia el TC, se recalculan los dólares.
            $oldAmountUsd = $tx->amount_usd;
            $newAmountUsd = Money::arsToUsd($tx->amount_ars, $exchangeRate);

            $tx->update([
                'status'        => TransactionStatus::Confirmed,
                'exchange_rate' => $exchangeRate,
                'amount_usd'    => $newAmountUsd,
                'confirmed_at'  => now(),
                'confirmed_by'  => auth()->id(),
                'notes'         => $request->input('notes', $tx->notes),
            ]);

            if ($tx->type === TransactionType::Deposit) {
                // El saldo no había cambiado al crear el depósito pendiente.
                // Se acredita ahora con el monto recalculado.
                $tx->user->increment('balance_usd', $newAmountUsd);
            } else {
                // El retiro ya había descontado old_amount_usd al crear la transacción.
                // Si el TC cambió, ajustamos la diferencia:
                //   TC sube → menos USD → refund de la diferencia
                //   TC baja → más USD  → descuento adicional
                $adjustment = Money::sub($oldAmountUsd, $newAmountUsd);
                if (! Money::isZero($adjustment)) {
                    $tx->user->increment('balance_usd', $adjustment);
                }
            }
        });

        return response()->json([
            'data'    => new TransactionResource($transaction->fresh()->load(['user', 'reviewer'])),
            'message' => 'Transacción confirmada.',
        ]);
    }

    /**
     * Rechaza una transacción pendiente.
     * Si era un retiro, restaura el saldo del miembro.
     *
     * PUT /api/admin/transactions/{transaction}/reject
     */
    public function reject(Request $request, Transaction $transaction): JsonResponse
    {
        DB::transaction(function () use ($request, $transaction) {
            // Re-leer con lock (ver confirm): evita doble refund ante requests concurrentes.
            $tx = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($tx->status !== TransactionStatus::Pending) {
                abort(422, 'Solo se pueden rechazar transacciones pendientes.');
            }

            $tx->update([
                'status'       => TransactionStatus::Rejected,
                'confirmed_at' => now(),
                'confirmed_by' => auth()->id(),
                'notes'        => $request->input('notes', $tx->notes),
            ]);

            // Retiro: restaurar el saldo que fue descontado al crear la transacción
            if ($tx->type === TransactionType::Withdrawal) {
                $tx->user->increment('balance_usd', $tx->amount_usd);
            }
            // Depósito rechazado: el saldo nunca cambió, nada que restaurar
        });

        return response()->json([
            'data'    => new TransactionResource($transaction->fresh()->load(['user', 'reviewer'])),
            'message' => 'Transacción rechazada.',
        ]);
    }
}
