<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /** Liveness: tiến trình ứng dụng còn sống (không chạm tới dịch vụ ngoài). */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Readiness: các phụ thuộc bắt buộc (database) sẵn sàng. */
    public function ready(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'down';
        }

        $ok = $database === 'ok';

        return response()->json(
            ['status' => $ok ? 'ok' : 'down', 'checks' => ['database' => $database]],
            $ok ? 200 : 503,
        );
    }
}
