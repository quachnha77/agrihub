<?php

namespace App\Support\Audit;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Tự ghi audit log khi model được tạo / sửa / xóa / khôi phục (kèm before/after của các trường thay đổi).
 *
 * Model có thể khai báo:
 *  - `protected string $auditModule`  : khu vực chức năng (mặc định: tên model dạng snake_case)
 *  - `protected array $auditIgnore`   : thuộc tính không đưa vào log (vd. bộ đếm đăng nhập)
 *  - `auditLabel()`                   : nhãn dễ đọc của đối tượng
 *
 * Thuộc tính nằm trong $hidden luôn bị che. Thay đổi qua quan hệ/pivot hoặc query builder KHÔNG tự ghi:
 * những nơi đó phải gọi AuditLogger::record() với sự kiện có ngữ nghĩa rõ ràng.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => app(AuditLogger::class)->modelEvent($model, 'created'));
        static::updated(fn (Model $model) => app(AuditLogger::class)->modelEvent($model, 'updated'));
        static::deleted(fn (Model $model) => app(AuditLogger::class)->modelEvent($model, 'deleted'));

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(fn (Model $model) => app(AuditLogger::class)->modelEvent($model, 'restored'));
        }
    }

    public function auditModule(): string
    {
        return property_exists($this, 'auditModule') ? $this->auditModule : Str::snake(class_basename($this));
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return property_exists($this, 'auditIgnore') ? $this->auditIgnore : [];
    }

    public function auditLabel(): ?string
    {
        return $this->getAttribute('name') ?? $this->getAttribute('email') ?? $this->getAttribute('code');
    }
}
