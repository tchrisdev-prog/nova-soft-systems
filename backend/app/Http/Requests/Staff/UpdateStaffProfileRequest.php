<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Enums\StaffSpecialty;
use App\Models\StaffProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La jornada se recibe en horas (como la piensa la gente) y se guarda en
 * minutos. El costo por hora solo lo puede enviar quien puede verlo: a los
 * demás se les rechaza el campo en vez de ignorarlo en silencio.
 */
class UpdateStaffProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', StaffProfile::class);
    }

    public function rules(): array
    {
        $canCost = $this->user()->can('manageCost', StaffProfile::class);

        return [
            'site_id' => ['required', 'integer', Rule::exists('sites', 'id')->where('is_active', true)],
            'position' => ['required', 'string', 'min:2', 'max:100'],
            'specialty' => ['nullable', Rule::enum(StaffSpecialty::class)],
            'weekly_hours' => ['required', 'numeric', 'min:1', 'max:60', 'multiple_of:0.5'],
            'hourly_cost' => $canCost
                ? ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000']
                : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['hourly_cost.prohibited' => 'No tienes permiso para registrar costos por hora.'];
    }

    public function attributes(): array
    {
        return [
            'site_id' => 'sede',
            'position' => 'cargo',
            'specialty' => 'especialidad',
            'weekly_hours' => 'horas semanales',
            'hourly_cost' => 'costo por hora',
        ];
    }
}
