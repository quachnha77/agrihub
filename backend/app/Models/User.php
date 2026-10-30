<?php

namespace App\Models;

use App\Services\Rbac\PermissionResolver;
use App\Support\Audit\Auditable;
use App\Support\HasPublicUuid;
use App\Support\Platform\SupportSession;
use App\Support\Rbac\Catalog;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * tenant_id, is_platform_admin, status... KHÔNG nằm trong $fillable: chỉ được gán rõ ràng bởi code nghiệp vụ
 * (onboarding, quản trị), không bao giờ từ request thô. Cô lập dữ liệu dựa vào PostgreSQL RLS (xem TenantContext);
 * model này không dùng global scope vì việc xác thực phải tìm user trước khi biết Tenant.
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasPublicUuid, Notifiable, SoftDeletes;

    protected string $auditModule = 'user';

    /** Bộ đếm/dấu thời gian thay đổi ở mỗi lần đăng nhập: đã có sự kiện auth.* riêng, không ghi thành thay đổi hồ sơ. */
    protected array $auditIgnore = ['failed_login_attempts', 'locked_until', 'last_login_at'];

    /** @var array<string, string>|null cache quyền trong vòng đời của object (đặt lại mỗi request) */
    private ?array $permissionCache = null;

    /** @var list<int>|null */
    private ?array $branchIdCache = null;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** Email luôn lưu dạng chữ thường để đăng nhập không phân biệt hoa/thường. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function auditLabel(): ?string
    {
        return $this->email;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branches')->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
    }

    /**
     * Quyền hiệu lực: permission key => scope rộng nhất (own < branch < tenant).
     *
     * @return array<string, string>
     */
    public function permissionMap(): array
    {
        return $this->permissionCache ??= app(PermissionResolver::class)->forUser($this);
    }

    /**
     * Tenant mà user đang thao tác: Tenant của chính họ; với Platform Admin là Tenant của phiên hỗ trợ (nếu có).
     */
    public function effectiveTenantId(): ?int
    {
        return $this->is_platform_admin ? app(SupportSession::class)->tenantId() : $this->tenant_id;
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->branchIdCache = null;
    }

    /** Scope hiện có cho quyền, hoặc null nếu không có. */
    public function permissionScope(string $key): ?string
    {
        return $this->permissionMap()[$key] ?? null;
    }

    public function hasPermission(string $key, string $minScope = 'own'): bool
    {
        return Catalog::scopeRank($this->permissionScope($key)) >= Catalog::scopeRank($minScope);
    }

    /**
     * ID các chi nhánh được gán cho user (dùng cho scope "branch").
     *
     * @return list<int>
     */
    public function branchIds(): array
    {
        return $this->branchIdCache ??= app(TenantContext::class)->runForTenant(
            (int) $this->tenant_id,
            fn () => DB::table('user_branches')->where('user_id', $this->id)->pluck('branch_id')->all(),
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
