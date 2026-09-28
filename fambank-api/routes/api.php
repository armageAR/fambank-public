<?php

use App\Http\Controllers\Api\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// ─── Públicas ────────────────────────────────────────────────────────────────

Route::get('/exchange-rate', [ExchangeRateController::class, 'show']);
Route::get('/vapid-public-key', fn () => response()->json(['key' => config('app.vapid_public_key')]));

Route::prefix('auth')->group(function () {
    Route::post('/login',          [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
});

// ─── Autenticadas ────────────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('/logout',  [AuthController::class, 'logout']);
        Route::get('/me',       [AuthController::class, 'me']);
        Route::put('/profile',  [AuthController::class, 'updateProfile']);
    });

    // ─── Transacciones (member + admin) ──────────────────────────────────────
    Route::get('transactions',                      [TransactionController::class, 'index']);
    Route::post('transactions',                     [TransactionController::class, 'store']);
    Route::delete('transactions/{transaction}',     [TransactionController::class, 'cancel']);

    // ─── Push subscriptions ───────────────────────────────────────────────────
    Route::post('push-subscriptions',               [PushSubscriptionController::class, 'store']);
    Route::post('push-subscriptions/test',          [PushSubscriptionController::class, 'test']);
    Route::delete('push-subscriptions',             [PushSubscriptionController::class, 'destroy']);

    // ─── Admin ───────────────────────────────────────────────────────────────

    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('users',                           [AdminUserController::class, 'index']);
        Route::post('users',                          [AdminUserController::class, 'store']);
        Route::put('users/{user}',                    [AdminUserController::class, 'update']);
        Route::get('users/{user}/transactions',       [AdminUserController::class, 'transactions']);
        Route::put('users/{user}/password',           [AdminUserController::class, 'updatePassword']);
        Route::put('users/{user}/reset-password',     [AdminUserController::class, 'sendResetPassword']);
        Route::put('users/{user}/activate',                            [AdminUserController::class, 'activate']);
        Route::delete('users/{user}',                              [AdminUserController::class, 'destroy']);
        Route::get('transactions',                                 [AdminTransactionController::class, 'index']);
        Route::put('transactions/{transaction}/confirm',           [AdminTransactionController::class, 'confirm']);
        Route::put('transactions/{transaction}/reject',            [AdminTransactionController::class, 'reject']);
    });

});

// ─── Health ──────────────────────────────────────────────────────────────────

Route::get('/health', function () {
    $db = false;
    $dbError = null;

    try {
        DB::connection()->getPdo();
        DB::statement('SELECT 1');
        $db = true;
    } catch (\Exception $e) {
        $dbError = $e->getMessage();
    }

    return response()->json([
        'status'   => $db ? 'ok' : 'error',
        'services' => [
            'api' => [
                'status'  => 'ok',
                'version' => app()->version(),
                'env'     => app()->environment(),
            ],
            'database' => [
                'status' => $db ? 'ok' : 'error',
                'error'  => $dbError,
            ],
        ],
        'timestamp' => now()->toIso8601String(),
    ], $db ? 200 : 503);
});
