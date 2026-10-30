<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một thông báo trong ứng dụng gửi cho MỘT người dùng (user_id). tenant_id là Tenant của người nhận;
 * NULL = thông báo của Platform Admin, chỉ đọc được ở chế độ nền tảng (RLS). Chỉ AlertService/InAppChannel được tạo.
 */
class Notice extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const LEVEL_INFO = 'info';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_CRITICAL = 'critical';

    protected $guarded = ['id', 'uuid', 'tenant_id', 'user_id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /** Thông báo của Platform Admin không có Tenant. Người nhận không thể đọc nhầm Tenant khác: RLS ẩn dòng có tenant_id NULL. */
    public function allowsNullTenant(): bool
    {
        return true;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
