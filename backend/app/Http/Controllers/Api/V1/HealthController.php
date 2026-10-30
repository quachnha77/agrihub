<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Services\Monitoring\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /** Liveness: tiến trình ứng dụng còn sống (không chạm tới dịch vụ ngoài). */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Cho công cụ giám sát chủ động hỏi (Uptime Kuma, Zabbix...): tình trạng chung, không có chi tiết. 200 khi bình thường hoặc cần chú ý,
     * 503 khi nghiêm trọng (công cụ báo động theo mã trạng thái). Cần MONITOR_TOKEN (header `Authorization: Bearer ...` hoặc `X-Monitor-Token`);
     * chưa cấu hình mã thì endpoint không tồn tại.
     */
    public function monitor(Request $request, SystemHealth $health): JsonResponse
    {
        $token = config('monitoring.token');

        abort_if(! is_string($token) || $token === '', 404);

        $given = $request->bearerToken() ?? $request->header('X-Monitor-Token');

        if (! is_string($given) || ! hash_equals($token, $given)) {
            return Problem::response(401, 'unauthenticated', 'Mã giám sát không đúng.');
        }

        $result = $health->evaluate();
        $failing = collect($result['components'])->whereNotIn('status', [SystemHealth::OK, SystemHealth::DISABLED])->pluck('key')->values()->all();

        return response()->json(
            ['status' => $result['status'], 'checked_at' => $result['checked_at'], 'failing' => $failing],
            $result['status'] === SystemHealth::CRITICAL ? 503 : 200,
        );
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
