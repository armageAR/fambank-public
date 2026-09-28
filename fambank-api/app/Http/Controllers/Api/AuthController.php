<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Inicia sesión y devuelve un API token.
     *
     * POST /api/auth/login
     *
     * @response 200 { data: { token, user }, message }
     * @response 401 Credenciales incorrectas
     * @response 403 Cuenta desactivada
     * @response 422 Errores de validación
     * @response 429 Demasiados intentos
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $key = 'login:' . Str::lower($request->input('username'));

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Demasiados intentos fallidos. Podés volver a intentarlo en {$seconds} segundos.",
                'data'    => ['available_in' => $seconds],
            ], 429);
        }

        if (! Auth::attempt($request->only('username', 'password'))) {
            RateLimiter::hit($key, 15 * 60);

            return response()->json(['message' => 'Las credenciales no son correctas.'], 401);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->active) {
            Auth::logout();

            return response()->json(['message' => 'Tu cuenta está desactivada. Contactá al administrador.'], 403);
        }

        RateLimiter::clear($key);

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken($request->device_name)->plainTextToken;

        return response()->json([
            'data'    => [
                'token' => $token,
                'user'  => new UserResource($user),
            ],
            'message' => 'Sesión iniciada correctamente.',
        ]);
    }

    /**
     * Cierra la sesión revocando el token actual.
     *
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * Devuelve el usuario autenticado.
     *
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }

    /**
     * Auto-edición del usuario autenticado: email y/o contraseña.
     * Al cambiar la contraseña se revocan los demás tokens (otras sesiones).
     *
     * PUT /api/auth/profile
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($request->filled('email')) {
            $user->email = $request->input('email');
        }

        $passwordChanged = $request->filled('password');
        if ($passwordChanged) {
            $user->password = $request->input('password');
        }

        $user->save();

        if ($passwordChanged) {
            // Revocar las demás sesiones conservando el token actual
            $currentTokenId = $user->currentAccessToken()?->id ?? null;
            $user->tokens()
                ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
                ->delete();
        }

        return response()->json([
            'data'    => new UserResource($user->fresh()),
            'message' => $passwordChanged
                ? 'Datos actualizados. Las demás sesiones fueron cerradas.'
                : 'Datos actualizados correctamente.',
        ]);
    }

    /**
     * Envía el email de reset de contraseña.
     * Responde igual independientemente de si el email existe (evita enumeración).
     *
     * POST /api/auth/forgot-password
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Si el email está registrado, recibirás un correo para restablecer tu contraseña.',
        ]);
    }

    /**
     * Restablece la contraseña y revoca todos los tokens del usuario.
     *
     * POST /api/auth/reset-password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('token', 'email', 'password', 'password_confirmation'),
            function (\App\Models\User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Contraseña restablecida correctamente.']);
    }
}
