<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một bản sao lưu (xem docs/BACKUP_DISASTER_RECOVERY.md). Việc ghi nhận hoạt động đi qua audit có ngữ nghĩa của BackupService. */
class Backup extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const SCOPE_TENANT = 'tenant';

    /** Toàn hệ thống (pg_dump): không thuộc Tenant nào, Tenant không bao giờ thấy được (RLS). */
    public const SCOPE_PLATFORM = 'platform';

    public const KIND_MANUAL = 'manual';

    public const KIND_AUTO = 'auto';

    public const KIND_UPLOAD = 'upload';

    public const KIND_PRE_RESTORE = 'pre_restore';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id', 'uuid', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'row_counts' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** Chỉ bản sao lưu toàn hệ thống được phép không có tenant_id (xem BelongsToTenant). */
    public function allowsNullTenant(): bool
    {
        return $this->scope === self::SCOPE_PLATFORM;
    }

    public function isPlatform(): bool
    {
        return $this->scope === self::SCOPE_PLATFORM;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function restoreRuns(): HasMany
    {
        return $this->hasMany(RestoreRun::class);
    }
}
