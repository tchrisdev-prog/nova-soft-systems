<?php

use App\Http\Controllers\Auth\MicrosoftAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'service' => 'API de Nova Sync Systems',
    'docs' => 'Consulta README.md del proyecto.',
]));

// Flujo OAuth 2.0 / OpenID Connect con Microsoft Entra ID.
Route::middleware('throttle:auth')->prefix('auth/microsoft')->name('auth.microsoft.')->group(function () {
    Route::get('redirect', [MicrosoftAuthController::class, 'redirect'])->name('redirect');
    Route::get('callback', [MicrosoftAuthController::class, 'callback'])->name('callback');
});
