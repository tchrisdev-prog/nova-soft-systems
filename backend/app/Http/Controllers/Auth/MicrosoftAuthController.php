<?php

namespace App\Http\Controllers\Auth;

use App\Auth\EntraId\AuthenticationFailed;
use App\Auth\EntraId\EntraIdConfig;
use App\Http\Controllers\Controller;
use App\Services\Auth\MicrosoftLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Flujo oficial OAuth 2.0 / OpenID Connect con Microsoft Entra ID.
 * El navegador nunca envía credenciales a esta aplicación: el usuario se
 * autentica en login.microsoftonline.com y vuelve con un código de un solo uso.
 */
class MicrosoftAuthController extends Controller
{
    public function redirect(EntraIdConfig $config): SymfonyRedirect
    {
        if (! $config->isConfigured()) {
            Log::warning('Inicio de sesión con Microsoft no configurado.', ['problems' => $config->problems()]);

            return $this->toFrontend('/login', ['error' => AuthenticationFailed::NOT_CONFIGURED]);
        }

        return Socialite::driver('entra')->redirect();
    }

    public function callback(Request $request, EntraIdConfig $config, MicrosoftLoginService $login): RedirectResponse
    {
        if (! $config->isConfigured()) {
            return $this->toFrontend('/login', ['error' => AuthenticationFailed::NOT_CONFIGURED]);
        }

        if ($request->filled('error')) {
            Log::notice('Microsoft devolvió un error en el inicio de sesión.', [
                'error' => $request->string('error')->limit(100)->toString(),
            ]);

            $reason = $request->input('error') === 'access_denied' ? 'access_denied' : AuthenticationFailed::PROVIDER_ERROR;

            return $this->toFrontend('/login', ['error' => $reason]);
        }

        try {
            $identity = Socialite::driver('entra')->identity();
            $user = $login->resolveUser($identity);
        } catch (InvalidStateException) {
            return $this->toFrontend('/login', ['error' => AuthenticationFailed::INVALID_STATE]);
        } catch (AuthenticationFailed $e) {
            Log::notice('Inicio de sesión rechazado.', ['reason' => $e->reason, 'detail' => $e->getMessage()]);

            return $this->toFrontend('/login', ['error' => $e->reason]);
        } catch (Throwable $e) {
            report($e);

            return $this->toFrontend('/login', ['error' => AuthenticationFailed::PROVIDER_ERROR]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->toFrontend('/app');
    }

    private function toFrontend(string $path, array $query = []): RedirectResponse
    {
        $url = config('nova.frontend_url').$path;

        return redirect()->away($query ? $url.'?'.http_build_query($query) : $url);
    }
}
