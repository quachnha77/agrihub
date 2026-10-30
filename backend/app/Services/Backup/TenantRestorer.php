<?php

namespace App\Services\Backup;

use App\Models\Tenant;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đọc, kiểm tra và nạp lại file do {@see TenantExporter} tạo (đã được giải mã bởi {@see BackupCrypto}).
 *
 * An toàn:
 *  - `inspect()` đọc TOÀN BỘ file và từ chối trước khi đụng vào database nếu: sai định dạng/phiên bản, sai Tenant,
 *    bảng ngoài danh sách cho phép, cột không còn tồn tại, dòng có cột thừa/thiếu, kiểu giá trị lạ, số dòng không khớp footer.
 *  - `restore()` chạy trong MỘT transaction: xóa dữ liệu hiện tại của Tenant ở các bảng CÓ trong file (con trước cha) rồi nạp lại.
 *    `tenant_id` của mọi dòng luôn bị GHI ĐÈ bằng Tenant đang khôi phục — file không thể ghi sang Tenant khác (RLS chặn thêm một lớp).
 *  - Chỉ các bảng có trong file mới bị thay thế; bảng mới thêm vào danh sách sau thời điểm sao lưu không bị xóa.
 */
class TenantRestorer
{
    private const MAX_LINE_BYTES = 4 * 1024 * 1024;

    /**
     * @return array{schema: string, created_at: string, tables: list<string>, row_counts: array<string, int>}
     */
    public function inspect(string $gzPath, Tenant $tenant): array
    {
        $header = null;
        $counts = [];
        $footer = null;
        $allowed = TenantExporter::tables();

        foreach ($this->lines($gzPath) as $number => $line) {
            if ($number === 1) {
                $header = $this->validateHeader($line, $tenant, $allowed);
                $counts = array_fill_keys(array_keys($header['tables']), 0);

                continue;
            }

            if ($footer !== null) {
                throw $this->invalid('Có dữ liệu sau phần kết thúc của file.');
            }

            if (($line['type'] ?? null) === 'footer') {
                $footer = $line;

                continue;
            }

            [$table] = $this->validateRow($line, $header['tables']);
            $counts[$table]++;
        }

        if ($header === null || $footer === null) {
            throw $this->invalid('File thiếu phần đầu hoặc phần kết thúc.');
        }

        if (($footer['row_counts'] ?? null) !== $counts) {
            throw $this->invalid('Số dòng dữ liệu không khớp với phần kết thúc của file.');
        }

        return [
            'schema' => (string) $header['schema'],
            'created_at' => (string) $header['created_at'],
            'tables' => array_keys($header['tables']),
            'row_counts' => $counts,
        ];
    }

    /**
     * Thay dữ liệu hiện tại của Tenant bằng nội dung file. Gọi khi đã ở context của Tenant này.
     *
     * @return array<string, int> số dòng đã nạp theo bảng
     */
    public function restore(string $gzPath, Tenant $tenant): array
    {
        $meta = $this->inspect($gzPath, $tenant); // kiểm tra toàn bộ trước khi đụng vào dữ liệu

        DB::transaction(function () use ($gzPath, $tenant, $meta) {
            foreach (array_reverse($meta['tables']) as $table) {
                DB::table($table)->where('tenant_id', $tenant->id)->delete();
            }

            $columns = [];
            $table = null;
            $batch = [];
            $size = 500;

            $flush = function () use (&$table, &$batch) {
                if ($batch !== []) {
                    DB::table($table)->insert($batch);
                    $batch = [];
                }
            };

            foreach ($this->lines($gzPath) as $number => $line) {
                if ($number === 1) {
                    $columns = $line['tables'];

                    continue;
                }
                if (($line['type'] ?? null) === 'footer') {
                    break;
                }

                if ($line['t'] !== $table) {
                    $flush();
                    $table = $line['t'];
                    $size = max(1, min(500, intdiv(60000, count($columns[$table]))));
                }

                $row = $line['r'];
                $row['tenant_id'] = $tenant->id; // luôn ghi đè: file không quyết định được Tenant đích
                $batch[] = $row;

                if (count($batch) >= $size) {
                    $flush();
                }
            }
            $flush();

            foreach ($meta['tables'] as $t) {
                $this->syncSequence($t);
            }
        });

        return $meta['row_counts'];
    }

    // --- nội bộ ---------------------------------------------------------------------------

    /**
     * @param  list<string>  $allowed
     * @return array{tables: array<string, list<string>>, schema: string, created_at: string}
     */
    private function validateHeader(array $h, Tenant $tenant, array $allowed): array
    {
        if (($h['type'] ?? null) !== 'header' || ($h['format'] ?? null) !== (int) config('backup.format_version')) {
            throw $this->invalid('Phiên bản định dạng file sao lưu không được hỗ trợ.');
        }

        if (($h['tenant_uuid'] ?? null) !== $tenant->uuid) {
            throw $this->invalid('File sao lưu này thuộc doanh nghiệp khác.');
        }

        if (! is_string($h['schema'] ?? null) || ! is_string($h['created_at'] ?? null) || ! is_array($h['tables'] ?? null)) {
            throw $this->invalid('Phần đầu của file không hợp lệ.');
        }

        foreach ($h['tables'] as $table => $columns) {
            if (! in_array($table, $allowed, true)) {
                throw $this->invalid("Bảng \"{$table}\" không còn nằm trong phạm vi khôi phục.");
            }

            $current = Schema::getColumnListing($table);
            if (! is_array($columns) || ! in_array('id', $columns, true) || ! in_array('tenant_id', $columns, true)
                || array_diff($columns, $current) !== []) {
                throw $this->invalid("Cấu trúc bảng \"{$table}\" trong file không khớp với phiên bản hiện tại của hệ thống.");
            }
        }

        return ['tables' => $h['tables'], 'schema' => $h['schema'], 'created_at' => $h['created_at']];
    }

    /**
     * @param  array<string, list<string>>  $tables
     * @return array{0: string}
     */
    private function validateRow(array $line, array $tables): array
    {
        $table = $line['t'] ?? null;
        $row = $line['r'] ?? null;

        if (! is_string($table) || ! isset($tables[$table]) || ! is_array($row)) {
            throw $this->invalid('Có dòng dữ liệu không hợp lệ hoặc thuộc bảng không được phép.');
        }

        $keys = array_keys($row);
        if (count($keys) !== count($tables[$table]) || array_diff($keys, $tables[$table]) !== []) {
            throw $this->invalid("Dòng dữ liệu của bảng \"{$table}\" có cột thừa hoặc thiếu.");
        }

        foreach ($row as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw $this->invalid("Dòng dữ liệu của bảng \"{$table}\" có giá trị không hợp lệ.");
            }
        }

        return [$table];
    }

    /** Đưa sequence của cột id lên ít nhất bằng id lớn nhất sau khi nạp (id gốc được giữ nguyên). */
    private function syncSequence(string $table): void
    {
        $sequence = DB::scalar('select pg_get_serial_sequence(?, ?)', [$table, 'id']);

        if ($sequence !== null) {
            DB::statement("select setval(?, greatest((select coalesce(max(id), 1) from \"{$table}\"), (select last_value from {$sequence})), true)", [$sequence]);
        }
    }

    /** @return Generator<int, array<string, mixed>> số dòng (từ 1) => dòng đã giải mã JSON */
    private function lines(string $gzPath): Generator
    {
        $gz = @gzopen($gzPath, 'rb');
        if ($gz === false) {
            throw $this->invalid('Không đọc được nội dung file.');
        }

        try {
            $number = 0;
            while (! gzeof($gz)) {
                $raw = gzgets($gz, self::MAX_LINE_BYTES);
                if ($raw === false) {
                    break;
                }
                if (! str_ends_with($raw, "\n")) {
                    if (! gzeof($gz)) {
                        throw $this->invalid('Có dòng dữ liệu quá dài.');
                    }
                    if (trim($raw) === '') {
                        break;
                    }
                }

                try {
                    $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw $this->invalid('Nội dung file không đúng định dạng.');
                }

                if (! is_array($decoded)) {
                    throw $this->invalid('Nội dung file không đúng định dạng.');
                }

                yield ++$number => $decoded;
            }
        } finally {
            gzclose($gz);
        }
    }

    private function invalid(string $message): BackupException
    {
        return new BackupException($message);
    }
}
