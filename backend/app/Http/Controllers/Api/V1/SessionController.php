<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\EntraId\EntraIdConfig;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    /** Estado público de la configuración de acceso (sin revelar valores). */
    public function config(EntraIdConfig $config): JsonResponse
    {
        return response()->json(['data' => [
            'provider' => 'microsoft-entra-id',
            'configured' => $config->isConfigured(),
            'login_url' => '/auth/microsoft/redirect',
        ]]);
    }

    public function logout(Request $request, AuditLogger $audit): Response
    {
        if ($user = $request->user()) {
            $audit->log('auth.logout', $user, $user);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
