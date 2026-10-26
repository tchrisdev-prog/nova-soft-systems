<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $logs = AuditLog::query()
            ->withPeople()
            ->when($validated['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->latest('created_at')->latest('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }
}
