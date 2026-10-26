<?php

namespace App\Http\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Formato de error uniforme para la API:
 *
 *   { "message": "Texto para el usuario", "code": "codigo_estable", "errors": { ... } }
 *
 * Nunca expone trazas ni detalles internos fuera del modo debug.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            return self::applies($request) ? self::json($e->getMessage(), $e->errorCode, $e->status) : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return self::applies($request)
                ? self::json('Tu sesión expiró o no has iniciado sesión.', 'unauthenticated', 401)
                : null;
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return self::applies($request)
                ? self::json('Revisa los datos ingresados.', 'validation_failed', 422, $e->errors())
                : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! self::applies($request)) {
                return null;
            }

            $status = $e->getStatusCode();
            [$message, $code] = match ($status) {
                403 => ['No tienes permiso para realizar esta acción.', 'forbidden'],
                404 => ['El recurso solicitado no existe.', 'not_found'],
                405 => ['Método no permitido.', 'method_not_allowed'],
                419 => ['La sesión de seguridad expiró. Recarga la página e inténtalo de nuevo.', 'csrf_mismatch'],
                429 => ['Demasiadas solicitudes. Espera un momento e inténtalo de nuevo.', 'too_many_requests'],
                503 => ['El servicio no está disponible temporalmente.', 'service_unavailable'],
                default => [$status >= 500 ? 'Ocurrió un error inesperado.' : 'No fue posible procesar la solicitud.', 'http_'.$status],
            };

            return self::json($message, $code, $status, headers: $e->getHeaders());
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! self::applies($request) || config('app.debug')) {
                return null;
            }

            return self::json('Ocurrió un error inesperado. Si el problema persiste, contacta al administrador.', 'server_error', 500);
        });
    }

    private static function applies(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private static function json(string $message, string $code, int $status, ?array $errors = null, array $headers = []): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $message,
            'code' => $code,
            'errors' => $errors,
        ], fn ($v) => $v !== null), $status, $headers);
    }
}
