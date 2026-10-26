<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\StaffSpecialty;
use App\Http\Controllers\Controller;
use App\Models\StaffProfile;
use App\Services\Projects\CapacityService;
use App\Support\BusinessCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Disponibilidad de los consultores. Sirve para planear (¿quién puede tomar
 * el proyecto nuevo?) y para detectar a quien está sobreasignado. Los costos
 * por hora nunca salen por aquí.
 */
class CapacityController extends Controller
{
    public function __construct(private readonly CapacityService $capacity) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewCapacity', StaffProfile::class);

        $validated = $request->validate([
            'weeks' => ['nullable', 'integer', Rule::in(CapacityService::HORIZONS)],
            'site_id' => ['nullable', 'integer'],
            'specialty' => ['nullable', Rule::enum(StaffSpecialty::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $this->capacity->weekly((int) ($validated['weeks'] ?? 8), $validated)]);
    }

    public function available(Request $request): JsonResponse
    {
        Gate::authorize('viewCapacity', StaffProfile::class);

        $validated = $request->validate([
            // 0 = mostrar a todos con su % libre (útil para sobreasignar a conciencia).
            'percent' => ['required', 'integer', 'min:0', 'max:100'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'site_id' => ['nullable', 'integer'],
            'specialty' => ['nullable', Rule::enum(StaffSpecialty::class)],
        ], [], ['percent' => 'porcentaje', 'from' => 'desde', 'to' => 'hasta']);

        // Máximo un año: más allá, la "disponibilidad" ya no dice nada útil.
        if (BusinessCalendar::parseDate($validated['from'])->addYear()->toDateString() < $validated['to']) {
            throw ValidationException::withMessages(['to' => 'El rango no puede ser mayor a un año.']);
        }

        return response()->json(['data' => $this->capacity->available(
            (int) $validated['percent'],
            $validated['from'],
            $validated['to'],
            $validated,
        )]);
    }
}
