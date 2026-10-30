<?php

namespace App\Support\Platform;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Phiên hỗ trợ (impersonation an toàn): Platform Admin được xem dữ liệu của MỘT Tenant ở chế độ chỉ đọc,
 * có lý do, có hạn, ràng buộc với đúng admin và phiên đăng nhập đó. Trạng thái nằm trong session phía server
 * (đăng xuất là hết), không bao giờ do client gửi lên.
 *
 * Đối tượng này là "scoped": mỗi request được nạp lại bởi middleware SetTenantContext.
 */
final class SupportSession
{
    public const KEY = 'platform.support_session';

    /** @var array<string, mixed>|null phiên hợp lệ của request hiện tại */
    private ?array $current = null;

    /** @var array<string, mixed>|null phiên vừa phát hiện hết hạn ở request này */
    private ?array $expired = null;

    /**
     * Nạp phiên từ session. Phiên hết hạn hoặc thuộc admin khác bị xóa.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(Request $request, User $admin): ?array
    {
        $this->current = $this->expired = null;

        if (! $request->hasSession()) {
            return null;
        }

        $data = $request->session()->get(self::KEY);

        if (! is_array($data)) {
            return null;
        }

        if (($data['admin_id'] ?? null) !== $admin->id) {
            $request->session()->forget(self::KEY);

            return null;
        }

        if (Carbon::parse($data['expires_at'], 'UTC')->isPast()) {
            $request->session()->forget(self::KEY);
            $this->expired = $data;

            return null;
        }

        return $this->current = $data;
    }

    /** Phiên vừa hết hạn (chỉ trả một lần) để middleware ghi audit. */
    public function takeExpired(): ?array
    {
        return tap($this->expired, fn () => $this->expired = null);
    }

    public function active(): bool
    {
        return $this->current !== null;
    }

    public function id(): ?string
    {
        return $this->current['id'] ?? null;
    }

    public function tenantId(): ?int
    {
        return $this->current['tenant_id'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function data(): ?array
    {
        return $this->current;
    }

    /** @return array<string, mixed> */
    public function begin(Request $request, User $admin, Tenant $tenant, string $reason, int $minutes): array
    {
        $now = Carbon::now('UTC');

        $data = [
            'id' => (string) Str::uuid(),
            'admin_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'tenant_uuid' => $tenant->uuid,
            'tenant_name' => $tenant->name,
            'reason' => $reason,
            'started_at' => $now->toIso8601String(),
            'expires_at' => $now->copy()->addMinutes($minutes)->toIso8601String(),
        ];

        $request->session()->put(self::KEY, $data);

        return $this->current = $data;
    }

    /** @return array<string, mixed>|null phiên vừa kết thúc */
    public function end(Request $request): ?array
    {
        $ended = $this->current;

        if ($request->hasSession()) {
            $request->session()->forget(self::KEY);
        }
        $this->current = null;

        return $ended;
    }

    /** Dạng trả về cho client (không lộ id nội bộ). */
    public static function present(array $data): array
    {
        return [
            'id' => $data['id'],
            'tenant' => ['id' => $data['tenant_uuid'], 'name' => $data['tenant_name']],
            'reason' => $data['reason'],
            'started_at' => $data['started_at'],
            'expires_at' => $data['expires_at'],
            'read_only' => true,
        ];
    }
}
