<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Quản lý người dùng toàn hệ thống cho Platform Admin (hỗ trợ khách hàng): tìm, khóa/mở khóa, cấp mật khẩu tạm.
 * Tài khoản Platform Admin chỉ được tạo bằng lệnh `agrihub:create-platform-admin`.
 */
class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tenant' => ['nullable', 'string', 'max:40'], // uuid hoặc "none" (Platform Admin)
            'status' => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->with(['tenant', 'roles'])->orderBy('name')->orderBy('id');

        if (($f['tenant'] ?? null) === 'none') {
            $query->whereNull('tenant_id');
        } elseif (! empty($f['tenant'])) {
            $query->whereHas('tenant', fn ($q) => $q->where('uuid', $f['tenant']));
        }

        $query
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%';
                $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('phone', 'ilike', $like));
            });

        return UserResource::collection($query->paginate($f['per_page'] ?? 20)->withQueryString());
    }

    public function update(Request $request, User $user): UserResource
    {
        $f = $request->validate([
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->users->assertNotSelf($request->user(), $user);

        // assertNotSelf đã đảm bảo người thao tác (một Platform Admin đang hoạt động) vẫn còn sau khi khóa người khác.
        $this->users->applyStatus($request->user(), $user, $f['status'], $f['reason'] ?? null);

        return new UserResource($user->refresh()->load(['tenant', 'roles']));
    }

    /** Cấp mật khẩu tạm cho người dùng của doanh nghiệp (quên mật khẩu, chưa có luồng email). Không áp dụng cho Platform Admin. */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        if ($user->is_platform_admin) {
            throw new ApiProblemException(422, 'not_allowed_for_platform_admin', 'Platform Admin tự đổi mật khẩu hoặc dùng lệnh quản trị.');
        }

        $this->users->assertNotSelf($request->user(), $user);
        $temporary = $this->users->applyPasswordReset($request->user(), $user);

        return (new UserResource($user->refresh()->load(['tenant', 'roles'])))
            ->additional(['temporary_password' => $temporary])
            ->response()
            ->header('Cache-Control', 'no-store');
    }
}
