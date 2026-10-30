<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NoticeResource;
use App\Models\Notice;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Hộp thông báo của CHÍNH người đăng nhập (Platform Admin hay người dùng Tenant). Không cần quyền riêng:
 * mọi truy vấn đều giới hạn user_id = người hiện tại, và RLS vẫn áp dụng cho người dùng Tenant.
 */
class NotificationController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();

        return $this->within($user, function () use ($f, $user, $request) {
            $mine = fn () => Notice::query()->where('user_id', $user->id);

            return NoticeResource::collection(
                $mine()->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
                    ->orderByDesc('created_at')->orderByDesc('id')
                    ->paginate($f['per_page'] ?? 20)->withQueryString(),
            )->additional(['unread_count' => $mine()->whereNull('read_at')->count()]);
        });
    }

    public function read(Request $request, string $notice): NoticeResource
    {
        $user = $request->user();

        return $this->within($user, function () use ($user, $notice) {
            $row = Notice::query()->where('user_id', $user->id)->where('uuid', $notice)->firstOrFail();

            if ($row->read_at === null) {
                $row->forceFill(['read_at' => now()])->save();
            }

            return new NoticeResource($row);
        });
    }

    public function readAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $updated = $this->within($user, fn () => Notice::query()->where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => now()]));

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /** Thông báo của Platform Admin có tenant_id NULL nên chỉ đọc được ở chế độ nền tảng; vẫn luôn lọc theo user_id. */
    private function within(User $user, Closure $callback): mixed
    {
        return $user->is_platform_admin ? $this->context->runAsPlatform($callback) : $callback();
    }
}
