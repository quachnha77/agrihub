<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Chỉ để ĐỌC audit log (bảng append-only, có Row-Level Security theo Tenant).
 * Ghi log luôn đi qua App\Services\Audit\AuditLogger. `occurred_at` cố ý không cast để giữ độ chính xác micro-giây
 * cho cursor pagination.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_logs';

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        $forbid = fn () => throw new LogicException('audit_logs chỉ được ghi qua AuditLogger và không bao giờ được sửa/xóa.');

        static::saving($forbid);
        static::deleting($forbid);
    }
}
