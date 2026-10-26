<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('permission:audit.view') o 'permission:users.view|users.manage'
 * (basta con uno de los permisos listados).
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        $required = explode('|', $permissions);

        if (! $user || ! collect($required)->contains(fn (string $code) => $user->hasPermission($code))) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
