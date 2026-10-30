<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Rbac\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PermissionController extends Controller
{
    /** Catalog quyền, nhóm theo resource, để dựng giao diện phân quyền. */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        $data = collect(Catalog::resources())->map(fn ($info, $resource) => [
            'resource' => $resource,
            'label' => $info['label'],
            'actions' => $info['actions'],
        ])->values();

        return response()->json(['data' => $data, 'scopes' => array_keys(Catalog::SCOPES)]);
    }
}
