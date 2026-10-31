<?php

namespace App\Support\Auth;

use App\Support\Tenancy\HostTenant;

/**
 * Phạm vi tìm tài khoản theo ĐỊA CHỈ truy cập (xem HostTenant): doanh nghiệp nào, hay quản trị nền tảng, hay chưa giới hạn.
 *
 * Email và số điện thoại chỉ duy nhất TRONG một doanh nghiệp (hoặc trong nhóm quản trị nền tảng), nên khi tra tài khoản
 * phải biết đang ở địa chỉ của ai.
 *
 * Là giá trị thuần (không giữ kết nối) để mang được vào hàng đợi: việc gửi email khôi phục chạy ngoài request.
 */
final class LoginScope
{
    public function __construct(
        /** HostTenant::TENANT | HostTenant::PLATFORM | HostTenant::UNRESTRICTED */
        public readonly string $kind,
        public readonly ?int $tenantId = null,
    ) {}

    public static function fromHost(HostTenant $host): self
    {
        return new self($host->kind(), $host->tenantId());
    }

    /** Phần của khóa giới hạn tốc độ: cùng một số điện thoại ở hai doanh nghiệp là hai tài khoản khác nhau. */
    public function key(): string
    {
        return match ($this->kind) {
            HostTenant::TENANT => 't'.$this->tenantId,
            HostTenant::PLATFORM => 'platform',
            default => 'any',
        };
    }
}
