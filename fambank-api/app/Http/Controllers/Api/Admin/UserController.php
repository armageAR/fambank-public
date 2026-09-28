<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\UpdatePasswordRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\UserResource;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class UserController extends Controller
{
    /**
     * Lista todos los usuarios con su saldo y último login.
     *
     * GET /api/admin/users
     */
    public function index(): JsonResponse
    {
        $users = User::orderBy('role')->orderBy('name')->get();

        return response()->json([
            'data' => UserResource::collection($users),
        ]);
    }

    /**
     * Crea un nuevo usuario. El admin define la contraseña inicial.
     *
     * POST /api/admin/users
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return response()->json([
            'data'    => new UserResource($user),
            'message' => 'Usuario creado correctamente.',
        ], 201);
    }

    /**
     * Edita los datos de un usuario (nombre, username, email, rol).
     * Un admin no puede quitarse el rol admin a sí mismo.
     *
     * PUT /api/admin/users/{user}
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        if (
            $request->user()->id === $user->id
            && $request->filled('role')
            && $request->input('role') !== UserRole::Admin->value
        ) {
            return response()->json(['message' => 'No podés quitarte el rol de administrador a vos mismo.'], 422);
        }

        $user->update($request->validated());

        return response()->json([
            'data'    => new UserResource($user->fresh()),
            'message' => 'Usuario actualizado correctamente.',
        ]);
    }

    /**
     * Cambia la contraseña de cualquier usuario y revoca todos sus tokens.
     *
     * PUT /api/admin/users/{user}/password
     */
    public function updatePassword(UpdatePasswordRequest $request, User $user): JsonResponse
    {
        $user->forceFill(['password' => $request->input('password')])->save();
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Contraseña actualizada. Todos los tokens del usuario fueron revocados.',
        ]);
    }

    /**
     * Envía un email de reset de contraseña al usuario (iniciado por el admin).
     *
     * PUT /api/admin/users/{user}/reset-password
     */
    public function sendResetPassword(User $user): JsonResponse
    {
        Password::sendResetLink(['email' => $user->email]);

        return response()->json([
            'message' => "Se envió un email de restablecimiento a {$user->email}.",
        ]);
    }

    /**
     * Lista los movimientos de un usuario paginados de más nuevo a más viejo.
     *
     * GET /api/admin/users/{user}/transactions
     */
    public function transactions(User $user): JsonResponse
    {
        $transactions = Transaction::byMember($user)
            ->with(['creator', 'reviewer'])
            ->latest()
            ->paginate(20);

        return TransactionResource::collection($transactions)
            ->additional(['user' => new UserResource($user->fresh())])
            ->response();
    }

    /**
     * Reactiva un usuario previamente desactivado.
     *
     * PUT /api/admin/users/{user}/activate
     */
    public function activate(User $user): JsonResponse
    {
        $user->update(['active' => true]);

        return response()->json([
            'data'    => new UserResource($user),
            'message' => 'Usuario activado correctamente.',
        ]);
    }

    /**
     * Desactiva al usuario y revoca todos sus tokens.
     * El admin no puede desactivarse a sí mismo.
     *
     * DELETE /api/admin/users/{user}
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'No podés desactivar tu propia cuenta.'], 422);
        }

        $user->update(['active' => false]);
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Usuario desactivado y sesiones revocadas.',
        ]);
    }
}
