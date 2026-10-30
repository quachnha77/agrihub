<?php

namespace App\Services\Backup;

use Throwable;

/**
 * Cơ chế sao lưu / khôi phục TOÀN HỆ THỐNG bằng pg_dump / pg_restore (không biết gì về bản ghi `backups` hay audit).
 *
 * Khuôn dạng nội dung (trước khi mã hóa bằng BackupCrypto):
 *     "AGRIHUB-PLATFORM-DUMP/1\n" + dòng JSON mô tả + "\n" + kho lưu trữ pg_dump (định dạng custom)
 * Dòng mô tả (manifest) nằm CÙNG file nên chỉ cần file + khóa là khôi phục và đối chiếu được, kể cả khi database đã mất.
 */
class PlatformDump
{
    public const HEADER = "AGRIHUB-PLATFORM-DUMP/1\n";

    public const MAX_MANIFEST_BYTES = 1048576;

    public function __construct(private PgTools $pg, private BackupCrypto $crypto) {}

    /**
     * Sao lưu database hiện tại vào $encryptedPath (đã mã hóa, không có bản rõ nào ra đĩa).
     * Số dòng từng bảng và nội dung dump lấy từ CÙNG một snapshot nên đối chiếu được chính xác dù hệ thống đang chạy.
     *
     * @return array<string, mixed> manifest
     */
    public function create(string $encryptedPath): array
    {
        $connection = $this->pg->connection();
        $skip = $this->excluded();
        $pdo = $this->pg->pdo();

        try {
            $this->pg->assertBackupRole($pdo);

            $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $snapshot = (string) $pdo->query('SELECT pg_export_snapshot()')->fetchColumn();

            $manifest = [
                'format' => 1,
                'created_at' => now('UTC')->toIso8601String(),
                'database' => $connection['database'],
                'server_version' => (string) $pdo->query('SHOW server_version')->fetchColumn(),
                'schema_version' => $this->pg->latestMigration($pdo),
                'excluded_data' => $skip,
                'row_counts' => $this->pg->rowCounts($pdo, $skip),
            ];

            $prefix = self::HEADER.json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

            $this->pg->streamDump($snapshot, $skip, fn ($stdout) => $this->crypto->encryptStream($stdout, $encryptedPath, $prefix));

            $pdo->exec('COMMIT');

            return $manifest;
        } finally {
            $pdo = null; // đóng kết nối => snapshot được giải phóng
        }
    }

    /**
     * Giải mã $encryptedPath và nạp vào database $database (phải tồn tại và còn trống). Lượt 1 xác thực TOÀN BỘ file,
     * chỉ khi file nguyên vẹn mới sang lượt 2 đưa dữ liệu cho pg_restore.
     *
     * @return array<string, mixed> manifest
     */
    public function restoreInto(string $encryptedPath, string $database, ?string $owner = null): array
    {
        $maxBytes = (int) config('backup.max_uncompressed_mb') * 1024 * 1024;

        // Lượt 1: xác thực toàn bộ + đọc manifest.
        $reader = new DumpHeaderReader;
        $this->crypto->decryptStream($encryptedPath, function (string $plain) use ($reader): void {
            $reader->push($plain);
        }, $maxBytes);
        $manifest = $reader->manifest();

        // Lượt 2: nạp vào pg_restore.
        $this->pg->streamRestore($database, $owner, function (callable $write) use ($encryptedPath, $maxBytes): void {
            $reader = new DumpHeaderReader;
            $this->crypto->decryptStream($encryptedPath, function (string $plain) use ($reader, $write): void {
                $rest = $reader->push($plain);

                if ($rest !== '') {
                    $write($rest);
                }
            }, $maxBytes);
        });

        return $manifest;
    }

    /**
     * Đối chiếu database vừa khôi phục với manifest: cùng tập bảng, cùng số dòng, bảng bỏ dữ liệu phải rỗng.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string> các sai lệch (rỗng = khớp hoàn toàn)
     */
    public function compare(array $manifest, string $database): array
    {
        $pdo = $this->pg->pdo($database);
        $expected = (array) ($manifest['row_counts'] ?? []);
        $skip = array_values((array) ($manifest['excluded_data'] ?? []));
        $actual = $this->pg->rowCounts($pdo, $skip);

        $problems = [];

        foreach ($expected as $table => $count) {
            if (! array_key_exists($table, $actual)) {
                $problems[] = "thiếu bảng {$table}";
            } elseif ($actual[$table] !== (int) $count) {
                $problems[] = "bảng {$table}: gốc {$count} dòng, sau khôi phục {$actual[$table]}";
            }
        }

        foreach (array_diff(array_keys($actual), array_keys($expected)) as $table) {
            $problems[] = "bảng {$table} không có trong bản sao lưu";
        }

        foreach ($this->pg->rowCounts($pdo, [], $skip) as $table => $count) {
            if ($count !== 0) {
                $problems[] = "bảng {$table} lẽ ra phải rỗng nhưng có {$count} dòng";
            }
        }

        return $problems;
    }

    public function createDatabase(string $name, ?string $owner = null): void
    {
        PgTools::assertIdentifier($name, 'database');

        if ($owner !== null) {
            PgTools::assertIdentifier($owner, 'role chủ sở hữu');
        }

        $sql = 'CREATE DATABASE '.$this->pg->quote($name).($owner !== null ? ' OWNER '.$this->pg->quote($owner) : '');

        $this->pg->pdo()->exec($sql);
    }

    public function databaseExists(string $name): bool
    {
        $stmt = $this->pg->pdo()->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $stmt->execute([$name]);

        return $stmt->fetchColumn() !== false;
    }

    /** Chỉ xóa được database tạm do chính hệ thống tạo (tiền tố verify) hoặc database đích vừa tạo ở lần chạy này. */
    public function dropDatabase(string $name): void
    {
        PgTools::assertIdentifier($name, 'database');

        if ($this->isLive($name)) {
            throw new BackupException('Từ chối xóa database đang chạy của hệ thống.');
        }

        $this->pg->pdo()->exec('DROP DATABASE IF EXISTS '.$this->pg->quote($name).' WITH (FORCE)');
    }

    /** Database đang chạy của ứng dụng / đang được sao lưu: tuyệt đối không phải đích khôi phục hay xóa. */
    public function isLive(string $name): bool
    {
        $live = array_filter([
            (string) config('database.connections.'.config('database.default').'.database'),
            (string) config('backup.platform.database'),
        ]);

        return in_array(strtolower($name), array_map('strtolower', $live), true);
    }

    /** @return list<string> */
    public function excluded(): array
    {
        return array_values(array_map('strval', (array) config('backup.platform.exclude_table_data')));
    }

    /**
     * Khôi phục vào một database MỚI tên $target. Tạo ra, nạp, đối chiếu; thất bại ở bất kỳ bước nào thì xóa database đã
     * tạo (không để lại bản khôi phục dở dang). Không bao giờ ghi đè database đang chạy.
     *
     * @return array<string, mixed> manifest
     */
    public function restoreIntoNewDatabase(string $encryptedPath, string $target, ?string $owner = null): array
    {
        PgTools::assertIdentifier($target, 'database đích');

        if ($this->isLive($target) || strtolower($target) === strtolower((string) $this->pg->pdo()->query('SELECT current_database()')->fetchColumn())) {
            throw new BackupException("Từ chối khôi phục đè lên database đang chạy ({$target}). Hãy chọn tên database mới.");
        }

        if ($this->databaseExists($target)) {
            throw new BackupException("Database {$target} đã tồn tại. Hãy chọn tên khác hoặc tự xóa nó trước khi khôi phục.");
        }

        $this->createDatabase($target, $owner);

        try {
            $manifest = $this->restoreInto($encryptedPath, $target, $owner);
            $problems = $this->compare($manifest, $target);

            if ($problems !== []) {
                throw new BackupException('Dữ liệu sau khôi phục không khớp bản sao lưu: '.implode('; ', array_slice($problems, 0, 5)));
            }

            return $manifest;
        } catch (Throwable $e) {
            try {
                $this->dropDatabase($target);
            } catch (Throwable) {
                // giữ nguyên lỗi gốc; database dở dang sẽ được báo trong thông điệp của lệnh gọi
            }

            throw $e;
        }
    }
}
