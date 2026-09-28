<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Exceptions\ExchangeRateUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\UserResource;
use App\Jobs\NotifyAdminsOfPendingTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    /**
     * Últimas 20 transacciones del miembro autenticado (más recientes primero).
     *
     * GET /api/transactions
     */
    public function index(Request $request): JsonResponse
    {
        $transactions = Transaction::byMember($request->user())
            ->with(['reviewer', 'creator'])
            ->latest()
            ->paginate(20);

        return TransactionResource::collection($transactions)
            ->additional(['user' => new UserResource($request->user()->fresh())])
            ->response();
    }

    /**
     * Crea una transacción.
     * - Miembro: queda en pending; retiro descuenta saldo inmediatamente.
     *   El tipo de cambio se obtiene siempre del servidor (el cliente no puede fijarlo).
     * - Admin: queda confirmada y el saldo cambia al instante. El admin sí define el TC.
     *
     * POST /api/transactions
     *
     * @response 503 La cotización no está disponible (solo members)
     */
    public function store(CreateTransactionRequest $request, ExchangeRateService $exchangeRates): JsonResponse
    {
        $authUser = $request->user();
        $isAdmin  = $authUser->role === UserRole::Admin;
        $type     = TransactionType::from($request->input('type'));

        if ($isAdmin) {
            $exchangeRate = $request->input('exchange_rate');
        } else {
            // El member no elige el TC: se consulta la cotización blue al momento de grabar.
            try {
                $rate = $exchangeRates->getBlue();
            } catch (ExchangeRateUnavailableException $e) {
                Log::warning('Transacción rechazada: cotización no disponible', [
                    'user_id' => $authUser->id,
                    'type'    => $type->value,
                ]);

                return response()->json([
                    'message' => 'No se pudo obtener la cotización del dólar en este momento. Volvé a intentarlo en unos minutos.',
                ], 503);
            }

            // Depósito: el banco "vende" dólares → cotización de venta.
            // Retiro: el banco "compra" dólares → cotización de compra.
            $exchangeRate = $type === TransactionType::Deposit ? $rate['sell'] : $rate['buy'];
        }

        $amountArs = $request->input('amount_ars');
        $amountUsd = Money::arsToUsd($amountArs, $exchangeRate);

        $transaction = DB::transaction(function () use (
            $authUser, $isAdmin, $type, $amountUsd, $exchangeRate, $amountArs, $request
        ) {
            // Lock del miembro para que el check de saldo y el débito sean atómicos
            // frente a transacciones concurrentes.
            $memberId = $isAdmin ? $request->input('user_id') : $authUser->id;
            $member   = User::whereKey($memberId)->lockForUpdate()->firstOrFail();

            if ($type === TransactionType::Withdrawal) {
                $currentBalance = $member->balance_usd ?? '0';
                if (Money::greaterThan($amountUsd, $currentBalance)) {
                    abort(422, 'Saldo insuficiente. El saldo disponible es USD ' . number_format((float) $currentBalance, 2));
                }
            }

            if ($isAdmin) {
                // Confirmada inmediatamente
                $tx = Transaction::create([
                    'user_id'       => $member->id,
                    'created_by'    => $authUser->id,
                    'type'          => $type,
                    'status'        => TransactionStatus::Confirmed,
                    'amount_usd'    => $amountUsd,
                    'amount_ars'    => $amountArs,
                    'exchange_rate' => $exchangeRate,
                    'notes'         => $request->input('notes'),
                    'confirmed_at'  => now(),
                    'confirmed_by'  => $authUser->id,
                ]);

                if ($type === TransactionType::Deposit) {
                    $member->increment('balance_usd', $amountUsd);
                } else {
                    $member->decrement('balance_usd', $amountUsd);
                }
            } else {
                // Pendiente
                $tx = Transaction::create([
                    'user_id'       => $member->id,
                    'created_by'    => $authUser->id,
                    'type'          => $type,
                    'status'        => TransactionStatus::Pending,
                    'amount_usd'    => $amountUsd,
                    'amount_ars'    => $amountArs,
                    'exchange_rate' => $exchangeRate,
                    'notes'         => $request->input('notes'),
                ]);

                // Retiro: descontar saldo inmediatamente como reserva
                if ($type === TransactionType::Withdrawal) {
                    $member->decrement('balance_usd', $amountUsd);
                }
            }

            return $tx;
        });

        $member = $transaction->user;

        // Notificar al admin si la transacción quedó pendiente (en queue para
        // no sumar la latencia de los push services al request del member)
        if (! $isAdmin) {
            NotifyAdminsOfPendingTransaction::dispatch(
                title: '💰 Nueva operación pendiente',
                body: "{$member->name} solicita un {$transaction->type->label()} de $ " . number_format((float) $transaction->amount_ars, 0, ',', '.'),
                transactionId: $transaction->id,
            );
        }

        return response()->json([
            'data'    => new TransactionResource($transaction->load(['reviewer', 'creator'])),
            'user'    => new UserResource($member->fresh()),
            'message' => 'Transacción creada correctamente.',
        ], 201);
    }

    /**
     * Cancela una transacción pendiente.
     * Solo puede hacerlo el miembro dueño de la transacción.
     * Si era un retiro, restaura el saldo.
     *
     * DELETE /api/transactions/{transaction}
     */
    public function cancel(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No podés cancelar esta transacción.'], 403);
        }

        DB::transaction(function () use ($transaction) {
            // Re-leer con lock: el check de estado y el refund deben ser atómicos
            // para que dos cancelaciones concurrentes no dupliquen el refund.
            $tx = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($tx->status !== TransactionStatus::Pending) {
                abort(422, 'Solo se pueden cancelar transacciones pendientes.');
            }

            // Restaurar saldo si era un retiro (ya había sido descontado)
            if ($tx->type === TransactionType::Withdrawal) {
                $tx->user->increment('balance_usd', $tx->amount_usd);
            }

            $tx->update(['status' => TransactionStatus::Cancelled]);
        });

        return response()->json([
            'data'    => new TransactionResource($transaction->fresh()->load(['reviewer', 'creator'])),
            'user'    => new UserResource($transaction->user->fresh()),
            'message' => 'Transacción cancelada.',
        ]);
    }
}
