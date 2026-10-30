<?php

namespace App\Services\Backup;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Xuất dữ liệu nghiệp vụ của MỘT Tenant (các bảng trong `config('backup.tenant_tables')`) ra file NDJSON nén gzip:
 *
 *   {"type":"header", format, tenant_uuid, created_at, schema, tables:{bảng:[cột...]}}
 *   {"t":"bảng","r":{...}}  ...mỗi dòng dữ liệu một dòng, theo thứ tự cha → con
 *   {"type":"footer", row_counts:{bảng:số dòng}}
 *
 * Phải gọi khi đã ở đúng context Tenant (RLS cũng giới hạn lần nữa). Đọc trong một snapshot nhất quán (REPEATABLE READ)
 * khi không nằm trong transaction sẵn có. Cột nhị phân (bytea) chưa được hỗ trợ.
 */
class TenantExporter
{
    /** @return array<string, int> số dòng theo bảng */
    public function export(Tenant $tenant, string $gzPath): array
    {
        $tables = self::tables();
        $columns = [];
        foreach ($tables as $table) {
            $columns[$table] = Schema::getColumnListing($table);
        }

        $gz = gzopen($gzPath, 'wb6');
        if ($gz === false) {
            throw new BackupException('Không tạo được file sao lưu tạm.');
        }

        $own = DB::transactionLevel() === 0;

        try {
            if ($own) {
                DB::beginTransaction();
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            $this->line($gz, [
                'type' => 'header',
                'format' => (int) config('backup.format_version'),
                'tenant_uuid' => $tenant->uuid,
                'created_at' => now()->utc()->toIso8601String(),
                'schema' => self::schemaVersion(),
                'tables' => $columns,
            ]);

            $counts = [];
            foreach ($tables as $table) {
                $counts[$table] = 0;

                foreach (DB::table($table)->where('tenant_id', $tenant->id)->lazyById(1000, 'id') as $row) {
                    $this->line($gz, ['t' => $table, 'r' => $this->plain((array) $row, $table)]);
                    $counts[$table]++;
                }
            }

            $this->line($gz, ['type' => 'footer', 'row_counts' => $counts]);

            return $counts;
        } finally {
            gzclose($gz);
            if ($own) {
                DB::rollBack(); // chỉ đọc
            }
        }
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return array_values(config('backup.tenant_tables'));
    }

    /** Tên migration mới nhất đã chạy: dấu vết phiên bản schema của bản sao lưu. */
    public static function schemaVersion(): string
    {
        return (string) DB::table('migrations')->orderByDesc('id')->value('migration');
    }

    /** @param resource $gz */
    private function line($gz, array $data): void
    {
        gzwrite($gz, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array<string, scalar|null> */
    private function plain(array $row, string $table): array
    {
        foreach ($row as $column => $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new BackupException("Cột {$table}.{$column} có kiểu dữ liệu chưa được hỗ trợ khi sao lưu.");
            }
        }

        return $row;
    }
}
