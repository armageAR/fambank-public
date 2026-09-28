<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    /**
     * Verifica que el usuario autenticado tenga rol admin.
     * Debe usarse después de auth:sanctum.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== UserRole::Admin) {
            return response()->json(['message' => 'No tenés permiso para realizar esta acción.'], 403);
        }

        return $next($request);
    }
}
