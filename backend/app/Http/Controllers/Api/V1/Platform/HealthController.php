<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\SystemHealth;
use Illuminate\Http\JsonResponse;

/** Super Admin: sức khỏe hạ tầng (database, hàng đợi, scheduler, đĩa, cấu hình, sao lưu). Chỉ đọc. */
class HealthController extends Controller
{
    public function index(SystemHealth $health): JsonResponse
    {
        return response()->json(['data' => $health->evaluate()]);
    }
}
