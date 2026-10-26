<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\BillingModel;
use App\Enums\ClientSector;
use App\Enums\ContactType;
use App\Enums\ContractStatus;
use App\Enums\Coverage;
use App\Enums\HourMovementType;
use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\ServiceLine;
use App\Enums\StaffSpecialty;
use App\Enums\TimeEntryStatus;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Projects\CapacityService;
use Illuminate\Http\JsonResponse;

/**
 * Listas para los formularios (líneas de servicio, estados, sedes...). Así
 * la interfaz no duplica las opciones ni sus etiquetas.
 */
class ReferenceDataController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'service_lines' => ServiceLine::options(),
            'billing_models' => BillingModel::options(),
            'coverages' => Coverage::options(),
            'contract_statuses' => ContractStatus::options(),
            'hour_movement_types' => HourMovementType::options(),
            'client_sectors' => ClientSector::options(),
            'contact_types' => ContactType::options(),
            'project_kinds' => ProjectKind::options(),
            'project_statuses' => ProjectStatus::options(),
            'time_entry_statuses' => TimeEntryStatus::options(),
            'staff_specialties' => StaffSpecialty::options(),
            'capacity_horizons' => CapacityService::HORIZONS,
            'sites' => Site::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'city']),
            'timesheet_step_minutes' => (int) config('nova.timesheets.step_minutes', 15),
            'default_weekly_hours' => (int) config('nova.projects.default_weekly_hours', 42),
        ]]);
    }
}
