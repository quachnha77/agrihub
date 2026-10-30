<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\Platform\SupportSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Phiên hỗ trợ: Platform Admin xem dữ liệu của một Tenant ở chế độ chỉ đọc, có lý do và thời hạn.
 * Mọi truy cập trong phiên được ghi audit và hiện trong nhật ký của chính Tenant đó.
 */
class SupportSessionController extends Controller
{
    public function __construct(private SupportSession $support, private AuditLogger $audit) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->support->active() ? SupportSession::present($this->support->data()) : null]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $config = config('platform.support_session');

        $f = $request->validate([
            'reason' => ['required', 'string', 'min:'.$config['min_reason_length'], 'max:500'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:'.$config['max_minutes']],
        ]);

        if (! $request->hasSession()) {
            throw new ApiProblemException(400, 'stateful_request_required', 'Phiên hỗ trợ chỉ dùng được từ ứng dụng web đã đăng nhập.');
        }

        if ($this->support->active()) {
            throw new ApiProblemException(409, 'support_session_active', 'Đang có phiên hỗ trợ. Hãy kết thúc phiên hiện tại trước khi mở phiên mới.');
        }

        $minutes = $f['minutes'] ?? $config['default_minutes'];
        $data = $this->support->begin($request, $request->user(), $tenant, $f['reason'], $minutes);

        $this->audit->record(
            'platform', 'support_session_started', subject: $tenant, actor: $request->user(), reason: mb_substr($f['reason'], 0, 255),
            metadata: ['support_session' => $data['id'], 'minutes' => $minutes, 'expires_at' => $data['expires_at'], 'tenant_status' => $tenant->status],
        );

        return response()->json(['data' => SupportSession::present($data)], 201);
    }

    public function destroy(Request $request): Response
    {
        $ended = $this->support->end($request);

        if ($ended !== null) {
            $minutes = (int) round(Carbon::parse($ended['started_at'])->diffInSeconds(now()) / 60);

            $this->audit->record(
                'platform', 'support_session_ended', tenantId: $ended['tenant_id'], actor: $request->user(),
                metadata: ['support_session' => $ended['id'], 'ended_by' => 'admin', 'duration_minutes' => $minutes],
            );
        }

        return response()->noContent();
    }
}
