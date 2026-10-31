<?php

namespace Tests\Feature\Backup;

use App\Models\Backup;
use App\Models\RestoreRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Backup\BackupCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AdministersBackups;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\Concerns\UsesBackupTables;
use Tests\TestCase;

/**
 * Sao lưu / khôi phục dữ liệu của MỘT doanh nghiệp. Chỉ Super Admin làm được (đường dẫn /platform/tenants/{id}/backups…); doanh nghiệp
 * (kể cả Chủ doanh nghiệp) không có API hay quyền nào về việc này. Dữ liệu nghiệp vụ trong các test dùng bảng giả (UsesBackupTables).
 */
class TenantBackupTest extends TestCase
{
    use AdministersBackups, CreatesTenants, ReadsAuditLog, RefreshDatabase, UsesBackupTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupTables();
    }

    /** @return array{0: Tenant, 1: User} doanh nghiệp và Chủ doanh nghiệp (chỉ để thử rằng họ KHÔNG có quyền sao lưu) */
    private function shop(string $name = 'Cửa hàng Nông Phát'): array
    {
        [$t, $owner] = $this->makeTenant(['name' => $name]);

        return [$t, $owner];
    }

    /** Tạo file sao lưu giả (hợp lệ về mã hóa) từ các dòng tùy ý, để kiểm thử file độc hại. */
    private function craft(array $lines): UploadedFile
    {
        $plain = tempnam(sys_get_temp_dir(), 'agbk');
        $enc = tempnam(sys_get_temp_dir(), 'agbk');
        $gz = gzopen($plain, 'wb6');
        foreach ($lines as $line) {
            gzwrite($gz, json_encode($line, JSON_UNESCAPED_UNICODE)."\n");
        }
        gzclose($gz);
        app(BackupCrypto::class)->encryptFile($plain, $enc);

        return UploadedFile::fake()->createWithContent('crafted.agbk', file_get_contents($enc));
    }

    private function header(Tenant $t, ?array $tables = null): array
    {
        return [
            'type' => 'header', 'format' => 1, 'tenant_uuid' => $t->uuid, 'created_at' => now()->toIso8601String(), 'schema' => 'x',
            'tables' => $tables ?? ['bk_customers' => ['id', 'tenant_id', 'name', 'meta', 'active', 'created_at', 'updated_at']],
        ];
    }

    /** Super Admin tải một file lên làm bản sao lưu của $tenant (Import). */
    private function adminImport(Tenant $tenant, UploadedFile|array $file)
    {
        return $this->asBackupAdmin()->post($this->backupPath($tenant, '/import'), $file instanceof UploadedFile ? ['file' => $file] : $file, ['Accept' => 'application/json']);
    }

    // --- Sao lưu ---------------------------------------------------------------------------

    public function test_super_admin_creates_a_backup_that_is_listed_encrypted_and_checksummed(): void
    {
        [$t] = $this->shop();
        $this->seedBusinessData($t, 3);

        $res = $this->asBackupAdmin()->postJson($this->backupPath($t))
            ->assertStatus(202)
            ->assertJsonPath('data.kind', 'manual')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.scope', 'tenant')
            ->assertJsonPath('data.row_counts.bk_customers', 3)
            ->assertJsonPath('data.row_counts.bk_orders', 6);

        $backup = $this->platform(fn () => Backup::where('uuid', $res->json('data.id'))->firstOrFail());
        $stored = Storage::disk('backups')->get($backup->path);

        $this->assertSame(hash('sha256', $stored), $backup->checksum_sha256);
        $this->assertSame(strlen($stored), (int) $backup->size_bytes);
        $this->assertStringNotContainsString('Khách', $stored);
        $this->assertStringNotContainsString($t->uuid, $stored);
        $this->assertStringContainsString($t->uuid, $backup->path);

        $this->asBackupAdmin()->getJson($this->backupPath($t))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $backup->uuid);
        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'created', 'tenant_id' => $t->id]));
    }

    public function test_a_backup_with_no_data_tables_registered_is_still_valid(): void
    {
        config(['backup.tenant_tables' => []]);
        [$t] = $this->shop();

        $this->asBackupAdmin()->postJson($this->backupPath($t))->assertStatus(202)->assertJsonPath('data.status', 'completed');
    }

    // --- Khôi phục -------------------------------------------------------------------------

    public function test_restore_brings_the_data_back_exactly_with_original_ids_and_leaves_other_tenants_alone(): void
    {
        [$a] = $this->shop('Cửa hàng A');
        [$b] = $this->shop('Cửa hàng B');
        $this->seedBusinessData($a, 3);
        $this->seedBusinessData($b, 2, 'Khách của B');
        $original = $this->businessState($a);
        $stateB = $this->businessState($b);
        $backup = $this->adminBackup($a);

        // Làm hỏng dữ liệu hiện tại: xóa sạch rồi thêm dữ liệu khác.
        $this->wipeBusinessData($a);
        $this->seedBusinessData($a, 1, 'Dữ liệu mới');
        $this->assertNotSame($original, $this->businessState($a));

        $this->adminRestore($backup)->assertStatus(202)->assertJsonPath('data.status', 'completed')->assertJsonPath('data.row_counts.bk_customers', 3);

        $this->assertSame($original, $this->businessState($a), 'Dữ liệu (kể cả id, JSON, số tiền) phải giống hệt bản sao lưu');
        $this->assertSame($stateB, $this->businessState($b), 'Doanh nghiệp khác không bị ảnh hưởng');

        // Sequence được đẩy lên: thêm bản ghi mới không bị trùng id.
        $newId = $this->platform(fn () => DB::table('bk_customers')->insertGetId(['tenant_id' => $a->id, 'name' => 'Mới', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertGreaterThan(max(array_column($original['customers'], 'id')), $newId);
    }

    public function test_every_restore_first_takes_a_pre_restore_safety_backup_that_can_undo_it(): void
    {
        [$t] = $this->shop();
        $this->seedBusinessData($t, 2, 'Bản cũ');
        $old = $this->adminBackup($t);

        $this->wipeBusinessData($t);
        $this->seedBusinessData($t, 4, 'Bản hiện tại');
        $current = $this->businessState($t);

        $run = $this->adminRestore($old)->assertStatus(202)->json('data');
        $this->assertNotSame($current, $this->businessState($t));

        $pre = $this->platform(fn () => RestoreRun::where('uuid', $run['id'])->firstOrFail()->preBackup);
        $this->assertSame(Backup::KIND_PRE_RESTORE, $pre->kind);
        $this->assertSame(4, $pre->row_counts['bk_customers']);

        // Quay lại hiện trạng trước khi khôi phục.
        $this->adminRestore($pre)->assertStatus(202)->assertJsonPath('data.status', 'completed');
        $this->assertSame($current, $this->businessState($t));
    }

    public function test_restore_requires_the_admins_password_and_the_exact_tenant_name_and_changes_nothing_otherwise(): void
    {
        [$t] = $this->shop('Cửa hàng A');
        $this->seedBusinessData($t, 2);
        $backup = $this->adminBackup($t);
        $this->wipeBusinessData($t);

        $this->adminRestore($backup, 'Cửa hàng A', 'sai-mat-khau')->assertUnprocessable()->assertJsonPath('code', 'confirmation_failed');
        $this->adminRestore($backup, 'cửa hàng a')->assertUnprocessable()->assertJsonPath('code', 'confirmation_failed');
        $this->asBackupAdmin()->postJson($this->backupPath($t, "/{$backup->uuid}/restore"), [])->assertUnprocessable();

        $this->assertSame([], $this->businessState($t)['customers']);
        $this->assertSame(0, $this->platform(fn () => RestoreRun::count()));
        $this->assertSame(2, $this->auditCount(['module' => 'backup', 'action' => 'restore_confirmation_failed']));
    }

    public function test_restore_is_audited_and_recorded(): void
    {
        [$t] = $this->shop();
        $this->seedBusinessData($t);
        $backup = $this->adminBackup($t);
        $this->adminRestore($backup)->assertStatus(202);

        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'restore_requested', 'tenant_id' => $t->id]));
        $row = $this->lastAudit(['module' => 'backup', 'action' => 'restored', 'tenant_id' => $t->id]);
        $this->assertNotNull($row);
        $this->assertSame($this->backupAdmin()->id, (int) $row->actor_id);
        $this->assertArrayHasKey('pre_restore_backup', $row->metadata);

        $this->asBackupAdmin()->getJson($this->backupPath($t, '/restores'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'completed')->assertJsonPath('data.0.backup_id', $backup->uuid);
    }

    public function test_audit_log_is_never_overwritten_by_a_restore(): void
    {
        [$t] = $this->shop();
        $this->seedBusinessData($t);
        $backup = $this->adminBackup($t);
        $before = $this->auditCount(['tenant_id' => $t->id]);

        $this->adminRestore($backup)->assertStatus(202);

        $this->assertGreaterThan($before, $this->auditCount(['tenant_id' => $t->id]));
        $this->assertSame(2, $this->auditCount(['module' => 'backup', 'action' => 'created', 'tenant_id' => $t->id]), 'Bản sao lưu thủ công + bản điểm quay lại');
    }

    public function test_a_tampered_stored_file_is_refused_and_data_is_untouched(): void
    {
        [$t] = $this->shop();
        $this->seedBusinessData($t, 2);
        $backup = $this->adminBackup($t);
        $this->wipeBusinessData($t);

        $content = Storage::disk('backups')->get($backup->path);
        Storage::disk('backups')->put($backup->path, substr($content, 0, -3).'xyz');

        $this->adminRestore($backup)->assertStatus(202)->assertJsonPath('data.status', 'failed');
        $this->assertSame([], $this->businessState($t)['customers']);
        $this->assertNull($this->platform(fn () => Tenant::find($t->id)->restoring_at), 'Cờ bảo trì phải được gỡ sau khi thất bại');
        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'restore_failed']));
    }

    public function test_incompatible_schema_fails_before_any_data_is_deleted(): void
    {
        [$t] = $this->shop();
        $this->seedBusinessData($t, 2);
        $backup = $this->adminBackup($t);

        $this->platform(fn () => Schema::table('bk_customers', fn ($table) => $table->dropColumn('meta')));

        $this->adminRestore($backup)->assertStatus(202)->assertJsonPath('data.status', 'failed');
        $this->assertSame(2, $this->platform(fn () => DB::table('bk_customers')->where('tenant_id', $t->id)->count()));
    }

    public function test_restoring_parents_while_children_still_reference_them_fails_atomically(): void
    {
        [$t] = $this->shop();
        $this->seedBusinessData($t, 2);

        config(['backup.tenant_tables' => ['bk_customers']]); // bản cũ chỉ có bảng cha
        $backup = $this->adminBackup($t);
        config(['backup.tenant_tables' => ['bk_customers', 'bk_orders']]);
        $before = $this->businessState($t);

        $this->adminRestore($backup)->assertStatus(202)->assertJsonPath('data.status', 'failed');

        $this->assertSame($before, $this->businessState($t), 'Lỗi giữa chừng => rollback toàn bộ, dữ liệu nguyên vẹn');
        $this->assertNull($this->platform(fn () => Tenant::find($t->id)->restoring_at));
    }

    public function test_only_tables_present_in_the_file_are_replaced(): void
    {
        [$t] = $this->shop();
        $this->seedBusinessData($t, 2);
        $this->platform(fn () => DB::table('bk_notes')->insert(['tenant_id' => $t->id, 'body' => 'ghi chú gốc']));

        // Bản sao lưu cũ chỉ có bk_notes (bk_customers được thêm vào phạm vi sau đó).
        config(['backup.tenant_tables' => ['bk_notes']]);
        $backup = $this->adminBackup($t);
        config(['backup.tenant_tables' => ['bk_notes', 'bk_customers']]);

        $this->platform(function () use ($t) {
            DB::table('bk_notes')->where('tenant_id', $t->id)->update(['body' => 'đã sửa']);
            DB::table('bk_customers')->where('tenant_id', $t->id)->update(['name' => 'Đã đổi']);
        });

        $this->adminRestore($backup)->assertStatus(202)->assertJsonPath('data.status', 'completed');

        $this->assertSame('ghi chú gốc', $this->platform(fn () => DB::table('bk_notes')->where('tenant_id', $t->id)->value('body')));
        $this->assertSame('Đã đổi', $this->businessState($t)['customers'][0]['name'], 'Bảng không có trong file được giữ nguyên');
    }

    public function test_a_second_restore_cannot_start_while_one_is_pending(): void
    {
        [$t] = $this->shop();
        $backup = $this->adminBackup($t);

        $this->platform(function () use ($t, $backup) {
            $run = new RestoreRun(['backup_id' => $backup->id, 'status' => 'running']);
            $run->tenant_id = $t->id;
            $run->save();
        });

        $this->adminRestore($backup)->assertStatus(409)->assertJsonPath('code', 'restore_in_progress');
    }

    public function test_the_tenant_is_in_maintenance_only_while_restoring(): void
    {
        [$t, $owner] = $this->shop();
        $this->platform(fn () => DB::table('tenants')->where('id', $t->id)->update(['restoring_at' => now()]));

        $this->spa()->actingAs($owner)->getJson('/api/v1/branches')->assertStatus(503)->assertJsonPath('code', 'tenant_restoring');

        // Cờ treo quá lâu thì không chặn mãi.
        $owner->unsetRelation('tenant');
        $this->platform(fn () => DB::table('tenants')->where('id', $t->id)->update(['restoring_at' => now()->subMinutes(config('backup.restore_lock_minutes') + 1)]));
        $this->spa()->actingAs($owner)->getJson('/api/v1/branches')->assertOk();
    }

    // --- Export / Import -------------------------------------------------------------------

    public function test_export_then_import_into_the_same_tenant_then_restore(): void
    {
        [$t] = $this->shop();
        $this->seedBusinessData($t, 3);
        $original = $this->businessState($t);
        $backup = $this->adminBackup($t);

        $download = $this->asBackupAdmin()->get($this->backupPath($t, "/{$backup->uuid}/download"))->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $file = $download->streamedContent();
        $this->assertSame($backup->checksum_sha256, hash('sha256', $file));
        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'downloaded']));

        $this->wipeBusinessData($t);

        $imported = $this->adminImport($t, UploadedFile::fake()->createWithContent('export.agbk', $file))
            ->assertCreated()->assertJsonPath('data.kind', 'upload')->assertJsonPath('data.status', 'completed')->assertJsonPath('data.row_counts.bk_customers', 3)
            ->assertJsonPath('data.original_name', 'export.agbk')->json('data.id');

        $this->adminRestore($this->platform(fn () => Backup::where('uuid', $imported)->firstOrFail()))->assertStatus(202)->assertJsonPath('data.status', 'completed');
        $this->assertSame($original, $this->businessState($t));
    }

    public function test_a_file_from_another_tenant_is_rejected_on_import(): void
    {
        [$a] = $this->shop('Cửa hàng A');
        [$tb] = $this->shop('Cửa hàng B');
        $this->seedBusinessData($a);
        $file = Storage::disk('backups')->get($this->adminBackup($a)->path);

        $this->adminImport($tb, UploadedFile::fake()->createWithContent('a.agbk', $file))->assertUnprocessable()->assertJsonPath('code', 'backup_invalid');

        $this->assertSame(0, $this->platform(fn () => Backup::where('tenant_id', $tb->id)->where('status', 'completed')->count()));
    }

    public function test_garbage_and_tampered_uploads_are_rejected_and_never_listed_as_usable(): void
    {
        [$t] = $this->shop();
        $this->seedBusinessData($t);
        $good = Storage::disk('backups')->get($this->adminBackup($t)->path);
        $tampered = $good;
        $tampered[strlen($tampered) - 5] = chr(ord($tampered[strlen($tampered) - 5]) ^ 1);

        foreach (['rác' => 'đây không phải file sao lưu', 'sửa đổi' => $tampered, 'cắt bớt' => substr($good, 0, 60)] as $content) {
            $this->adminImport($t, UploadedFile::fake()->createWithContent('x.agbk', $content))->assertUnprocessable()->assertJsonPath('code', 'backup_invalid');
        }

        $this->assertSame(1, $this->platform(fn () => Backup::where('status', 'completed')->count()), 'Chỉ bản sao lưu thật sự hợp lệ');
        $this->adminImport($t, [])->assertUnprocessable();
    }

    public function test_malicious_but_correctly_encrypted_files_are_refused(): void
    {
        [$t] = $this->shop();
        [$other] = $this->shop('Doanh nghiệp khác');
        $columns = ['id', 'tenant_id', 'name', 'meta', 'active', 'created_at', 'updated_at'];
        $row = ['id' => 1, 'tenant_id' => $t->id, 'name' => 'X', 'meta' => null, 'active' => true, 'created_at' => null, 'updated_at' => null];

        $cases = [
            'sai tenant' => [$this->header($other), ['type' => 'footer', 'row_counts' => ['bk_customers' => 0]]],
            'bảng không được phép' => [$this->header($t, ['users' => ['id', 'tenant_id']]), ['type' => 'footer', 'row_counts' => ['users' => 0]]],
            'cột không tồn tại' => [$this->header($t, ['bk_customers' => [...$columns, 'is_admin']]), ['type' => 'footer', 'row_counts' => ['bk_customers' => 0]]],
            'dòng thừa cột' => [$this->header($t), ['t' => 'bk_customers', 'r' => $row + ['x' => 1]], ['type' => 'footer', 'row_counts' => ['bk_customers' => 1]]],
            'bảng ngoài header' => [$this->header($t), ['t' => 'bk_orders', 'r' => $row], ['type' => 'footer', 'row_counts' => ['bk_customers' => 0]]],
            'sai số dòng' => [$this->header($t), ['t' => 'bk_customers', 'r' => $row], ['type' => 'footer', 'row_counts' => ['bk_customers' => 5]]],
            'thiếu footer' => [$this->header($t), ['t' => 'bk_customers', 'r' => $row]],
            'giá trị lồng' => [$this->header($t), ['t' => 'bk_customers', 'r' => ['meta' => ['a' => 1]] + $row], ['type' => 'footer', 'row_counts' => ['bk_customers' => 1]]],
            'phiên bản lạ' => [['format' => 99] + $this->header($t), ['type' => 'footer', 'row_counts' => ['bk_customers' => 0]]],
        ];

        foreach ($cases as $label => $lines) {
            $response = $this->adminImport($t, $this->craft($lines));
            $this->assertSame(422, $response->getStatusCode(), "Trường hợp \"{$label}\" phải bị từ chối");
            $this->assertSame('backup_invalid', $response->json('code'), $label);
        }
    }

    public function test_tenant_id_inside_a_file_can_never_redirect_rows_to_another_tenant(): void
    {
        [$a] = $this->shop('Cửa hàng A');
        [$b] = $this->shop('Cửa hàng B');
        $this->seedBusinessData($b, 2, 'Của B');
        $stateB = $this->businessState($b);

        $row = fn (int $id, int $tenantId) => ['id' => $id, 'tenant_id' => $tenantId, 'name' => "Tiêm nhiễm {$id}", 'meta' => null, 'active' => true, 'created_at' => null, 'updated_at' => null];
        $file = $this->craft([
            $this->header($a),
            ['t' => 'bk_customers', 'r' => $row(9001, $b->id)], // cố ghi vào B
            ['t' => 'bk_customers', 'r' => $row(9002, $a->id)],
            ['type' => 'footer', 'row_counts' => ['bk_customers' => 2]],
        ]);

        $id = $this->adminImport($a, $file)->assertCreated()->json('data.id');
        $this->adminRestore($this->platform(fn () => Backup::where('uuid', $id)->firstOrFail()), 'Cửa hàng A')->assertStatus(202)->assertJsonPath('data.status', 'completed');

        $this->assertSame($stateB, $this->businessState($b), 'Dữ liệu của B không đổi');
        $names = array_column($this->businessState($a)['customers'], 'name');
        $this->assertSame(['Tiêm nhiễm 9001', 'Tiêm nhiễm 9002'], $names, 'Cả hai dòng đều thuộc A');
    }

    // --- Chỉ Super Admin -------------------------------------------------------------------

    /** Yêu cầu 2026-10-31: sao lưu dữ liệu chỉ có ở Super Admin. Doanh nghiệp không có endpoint nào, kể cả Chủ doanh nghiệp. */
    public function test_tenant_users_have_no_backup_endpoints_or_permissions_at_all(): void
    {
        [$t, $owner, $b] = $this->makeTenant(['name' => 'Shop']);
        $backup = $this->adminBackup($t);

        foreach (['owner' => $owner, 'admin' => $this->makeUserIn($t, ['admin'], $b), 'warehouse' => $this->makeUserIn($t, ['warehouse'], $b)] as $label => $user) {
            $this->freshBrowser()->spa()->actingAs($user);

            $this->getJson('/api/v1/backups')->assertNotFound();
            $this->postJson('/api/v1/backups')->assertNotFound();
            $this->postJson('/api/v1/backups/import')->assertNotFound();
            $this->getJson('/api/v1/backups/restores')->assertNotFound();
            $this->getJson("/api/v1/backups/{$backup->uuid}")->assertNotFound();
            $this->getJson("/api/v1/backups/{$backup->uuid}/download")->assertNotFound();
            $this->postJson("/api/v1/backups/{$backup->uuid}/restore", ['password' => 'x', 'confirm_name' => 'Shop'])->assertNotFound();

            // Và đường dẫn của Super Admin thì họ không vào được.
            $this->getJson($this->backupPath($t))->assertForbidden();
            $this->postJson($this->backupPath($t))->assertForbidden();
            $this->getJson($this->backupPath($t, "/{$backup->uuid}/download"))->assertForbidden();
            $this->postJson($this->backupPath($t, "/{$backup->uuid}/restore"), ['password' => 'x', 'confirm_name' => 'Shop'])->assertForbidden();

            // Quyền sao lưu không còn trong catalog nên không cấp được cho ai (vai trò tùy chỉnh cũng vậy).
            if ($label !== 'warehouse') { // nhân viên kho không có quyền xem vai trò/phân quyền
                $this->assertStringNotContainsString('"backup', $this->getJson('/api/v1/permissions')->assertOk()->getContent(), $label);
            }
        }

        $this->assertSame(0, $this->platform(fn () => DB::table('permissions')->where('key', 'like', 'backup.%')->count()));
        $this->assertSame(0, $this->platform(fn () => DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('permissions.key', 'like', 'backup.%')->count()));
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        [$t] = $this->shop();

        $this->getJson($this->backupPath($t))->assertUnauthorized();
        $this->postJson($this->backupPath($t))->assertUnauthorized();
    }

    public function test_super_admin_backs_up_and_restores_a_chosen_tenant_and_the_owner_can_see_it_in_the_audit_log(): void
    {
        [$t, $owner] = $this->shop('Cửa hàng A');
        $this->seedBusinessData($t, 2);
        $original = $this->businessState($t);

        $backup = $this->adminBackup($t);
        $this->asBackupAdmin()->getJson($this->backupPath($t))->assertOk()->assertJsonCount(1, 'data');
        $this->asBackupAdmin()->get($this->backupPath($t, "/{$backup->uuid}/download"))->assertOk();

        $this->wipeBusinessData($t);
        $this->adminRestore($backup)->assertStatus(202)->assertJsonPath('data.status', 'completed');
        $this->assertSame($original, $this->businessState($t));

        // Minh bạch: Chủ doanh nghiệp không thao tác được nhưng vẫn thấy trong nhật ký hoạt động rằng dữ liệu của mình đã được sao lưu/khôi phục.
        $actions = $this->freshBrowser()->spa()->actingAs($owner)->getJson('/api/v1/audit-logs?module=backup')->assertOk()->json('data.*.action');
        $this->assertContains('restored', $actions);
        $this->assertContains('downloaded', $actions);
    }

    public function test_super_admin_cannot_mix_up_tenants(): void
    {
        [$a] = $this->shop('Cửa hàng A');
        [$b] = $this->shop('Cửa hàng B');
        $backupA = $this->adminBackup($a);

        $this->asBackupAdmin()->getJson($this->backupPath($b, "/{$backupA->uuid}"))->assertNotFound();
        $this->getJson($this->backupPath($b, "/{$backupA->uuid}/download"))->assertNotFound();
        $this->postJson($this->backupPath($b, "/{$backupA->uuid}/restore"), ['password' => self::ADMIN_PASSWORD, 'confirm_name' => 'Cửa hàng B'])->assertNotFound();
        $this->getJson($this->backupPath($b))->assertOk()->assertJsonCount(0, 'data');
    }

    // --- Bảo vệ cho các module tương lai ---------------------------------------------------

    public function test_every_table_with_a_tenant_id_is_either_backed_up_or_explicitly_excluded(): void
    {
        // Dùng cấu hình thật trong file (không phải bảng giả mà setUp đã ghi đè).
        $configured = require base_path('config/backup.php');
        $registered = $configured['tenant_tables'];
        $excluded = array_keys($configured['excluded_tenant_tables']);

        $tables = DB::table('information_schema.columns as c')
            ->join('pg_class as k', 'k.relname', '=', 'c.table_name')
            ->join('pg_namespace as n', fn ($j) => $j->on('n.oid', '=', 'k.relnamespace')->on('n.nspname', '=', 'c.table_schema'))
            ->where('c.table_schema', 'public')->where('c.column_name', 'tenant_id')
            ->where('k.relispartition', false)->whereIn('k.relkind', ['r', 'p'])
            ->where('c.table_name', 'not like', 'bk\_%')
            ->pluck('c.table_name')->all();

        $this->assertNotEmpty($tables);
        $missing = array_diff($tables, $registered, $excluded);
        $this->assertSame([], array_values($missing), 'Bảng có tenant_id chưa được đưa vào backup.tenant_tables hoặc backup.excluded_tenant_tables: '.implode(', ', $missing));
        $this->assertSame([], array_values(array_intersect($registered, $excluded)), 'Một bảng không thể vừa được sao lưu vừa bị loại trừ');

        foreach ($registered as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['id', 'tenant_id']), "Bảng {$table} phải có cột id và tenant_id");
        }
    }
}
