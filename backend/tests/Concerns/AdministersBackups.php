<?php

namespace Tests\Concerns;

use App\Models\Backup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Sao lưu / khôi phục dữ liệu của doanh nghiệp chỉ do SUPER ADMIN làm (đường dẫn /platform/tenants/{id}/backups…); doanh nghiệp không có
 * API này. Trait này gom các thao tác đó cho test. Cần dùng cùng CreatesTenants.
 */
trait AdministersBackups
{
    protected const ADMIN_PASSWORD = 'Admin-mat-khau-2026';

    private ?User $backupAdmin = null;

    /** Super Admin dùng chung trong một test. */
    protected function backupAdmin(): User
    {
        return $this->backupAdmin ??= $this->makePlatformAdmin(['password' => self::ADMIN_PASSWORD]);
    }

    /** Bắt đầu như một trình duyệt mới và đăng nhập Super Admin (mỗi người dùng một mật khẩu khác nhau nên không dùng chung phiên). */
    protected function asBackupAdmin(): static
    {
        return $this->freshBrowser()->spa()->actingAs($this->backupAdmin());
    }

    protected function backupPath(Tenant $tenant, string $suffix = ''): string
    {
        return "/api/v1/platform/tenants/{$tenant->uuid}/backups{$suffix}";
    }

    /** Super Admin tạo một bản sao lưu thủ công và đợi nó hoàn tất (queue chạy đồng bộ trong test). */
    protected function adminBackup(Tenant $tenant): Backup
    {
        $uuid = $this->asBackupAdmin()->postJson($this->backupPath($tenant))->assertStatus(202)->assertJsonPath('data.status', 'completed')->json('data.id');

        return $this->platform(fn () => Backup::where('uuid', $uuid)->firstOrFail());
    }

    /** Super Admin khôi phục; xác nhận bằng mật khẩu của chính họ và tên doanh nghiệp (mặc định: đúng tên). */
    protected function adminRestore(Backup $backup, ?string $name = null, string $password = self::ADMIN_PASSWORD): TestResponse
    {
        $tenant = $this->platform(fn () => Tenant::find($backup->tenant_id));

        return $this->asBackupAdmin()->postJson($this->backupPath($tenant, "/{$backup->uuid}/restore"), [
            'password' => $password, 'confirm_name' => $name ?? $tenant->name,
        ]);
    }
}
