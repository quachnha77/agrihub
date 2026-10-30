<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quản lý phiên đăng nhập của chính người dùng (xem thiết bị, đăng xuất từ xa).
 * Chỉ hoạt động khi SESSION_DRIVER=database. ID phiên thật không bao giờ trả về client: dùng mã băm làm định danh.
 */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($s) => [
                'id' => $this->handle($s->id),
                'current' => $s->id === $currentId,
                'ip_address' => $s->ip_address,
                'user_agent' => $s->user_agent,
                'last_active_at' => Carbon::createFromTimestamp($s->last_activity)->toIso8601String(),
            ]);

        return response()->json(['data' => $sessions->values()]);
    }

    public function destroy(Request $request, string $handle): Response|JsonResponse
    {
        $currentId = $request->session()->getId();

        $target = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->get()
            ->first(fn ($s) => hash_equals($this->handle($s->id), $handle));

        if ($target === null) {
            return Problem::response(404, 'not_found', 'Không tìm thấy phiên đăng nhập.');
        }

        if ($target->id === $currentId) {
            return Problem::response(422, 'cannot_revoke_current_session', 'Hãy dùng chức năng đăng xuất cho phiên hiện tại.');
        }

        DB::table('sessions')->where('id', $target->id)->delete();

        return response()->noContent();
    }

    /** Đăng xuất mọi thiết bị khác. */
    public function destroyOthers(Request $request): Response
    {
        DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return response()->noContent();
    }

    private function handle(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 32);
    }
}
