<?php

namespace App\Services\Backup;

use PDO;
use PDOException;
use Throwable;

/**
 * Điểm duy nhất chạm vào PostgreSQL ở mức quản trị cho sao lưu toàn hệ thống: chạy pg_dump / pg_restore và kết nối bằng
 * tài khoản sao lưu riêng (không bao giờ dùng tài khoản ứng dụng).
 *
 * Mật khẩu chỉ đi qua biến môi trường của tiến trình con (PGPASSWORD), không bao giờ nằm trên dòng lệnh hay trong log.
 * Lệnh được truyền dạng mảng (không qua shell) nên không có lỗ hổng chèn lệnh.
 */
class PgTools
{
    /** @return array{host: string, port: int, database: string, username: string, password: string, sslmode: ?string} */
    public function connection(): array
    {
        $c = (array) config('backup.platform');

        foreach (['username' => 'BACKUP_DB_USERNAME', 'password' => 'BACKUP_DB_PASSWORD', 'database' => 'BACKUP_DB_DATABASE hoặc DB_DATABASE', 'host' => 'BACKUP_DB_HOST'] as $key => $env) {
            if (! is_string($c[$key] ?? null) || $c[$key] === '') {
                throw new BackupException("Chưa cấu hình kết nối sao lưu toàn hệ thống ({$env}).");
            }
        }

        return [
            'host' => $c['host'], 'port' => (int) $c['port'], 'database' => $c['database'],
            'username' => $c['username'], 'password' => $c['password'],
            'sslmode' => is_string($c['sslmode'] ?? null) && $c['sslmode'] !== '' ? $c['sslmode'] : null,
        ];
    }

    public function pdo(?string $database = null): PDO
    {
        $c = $this->connection();
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=15', $c['host'], $c['port'], $database ?? $c['database']);

        if ($c['sslmode'] !== null) {
            $dsn .= ';sslmode='.$c['sslmode'];
        }

        try {
            return new PDO($dsn, $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            throw new BackupException('Không kết nối được database bằng tài khoản sao lưu: '.$this->clip($e->getMessage(), 200));
        }
    }

    /** Tài khoản sao lưu phải đọc được dữ liệu mọi Tenant: superuser hoặc BYPASSRLS. Thiếu thì dump sẽ rỗng hoặc lỗi. */
    public function assertBackupRole(PDO $pdo): void
    {
        $ok = $pdo->query('SELECT (rolsuper OR rolbypassrls)::int FROM pg_roles WHERE rolname = current_user')->fetchColumn();

        if ((int) $ok !== 1) {
            throw new BackupException('Tài khoản sao lưu phải có quyền BYPASSRLS (hoặc là superuser) để đọc dữ liệu mọi Tenant. Không dùng tài khoản của ứng dụng.');
        }
    }

    public function latestMigration(PDO $pdo): ?string
    {
        if ((int) $pdo->query("SELECT (to_regclass('public.migrations') IS NOT NULL)::int")->fetchColumn() !== 1) {
            return null;
        }

        $name = $pdo->query('SELECT migration FROM public.migrations ORDER BY id DESC LIMIT 1')->fetchColumn();

        return is_string($name) ? $name : null;
    }

    /**
     * Số dòng của từng bảng trong schema public (bảng cha của phân vùng đã gồm dòng của phân vùng con).
     *
     * @param  list<string>  $skip  bảng bỏ qua
     * @param  list<string>|null  $only  chỉ đếm các bảng này (nếu tồn tại)
     * @return array<string, int>
     */
    public function rowCounts(PDO $pdo, array $skip = [], ?array $only = null): array
    {
        $tables = $pdo->query(
            "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition ORDER BY c.relname",
        )->fetchAll(PDO::FETCH_COLUMN);

        $counts = [];

        foreach ($tables as $table) {
            if (in_array($table, $skip, true) || ($only !== null && ! in_array($table, $only, true))) {
                continue;
            }

            $counts[$table] = (int) $pdo->query('SELECT count(*) FROM public.'.$this->quote($table))->fetchColumn();
        }

        return $counts;
    }

    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * Chạy pg_dump (định dạng custom) trên đúng snapshot đã export và đưa stdout cho $consume (đọc đến hết).
     * Ném BackupException nếu pg_dump thoát với mã khác 0 — khi đó nội dung $consume đã đọc KHÔNG được tin dùng.
     *
     * @param  list<string>  $excludeData
     * @param  callable(resource): void  $consume
     */
    public function streamDump(string $snapshot, array $excludeData, callable $consume): void
    {
        $c = $this->connection();

        $cmd = [
            $this->binary('pg_dump'), '--format=custom', '--no-owner', '--no-acl', '--no-password', '--lock-wait-timeout=60s',
            '--snapshot='.$snapshot,
            '--host='.$c['host'], '--port='.$c['port'], '--username='.$c['username'], '--dbname='.$c['database'],
        ];

        foreach ($excludeData as $table) {
            $cmd[] = '--exclude-table-data=public.'.$table;
        }

        $errFile = $this->tempPath('.err');
        $proc = $this->open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, $c);

        try {
            fclose($pipes[0]);
            $consume($pipes[1]);
        } catch (Throwable $e) {
            $this->kill($proc, $pipes);
            @unlink($errFile);

            throw $e;
        }

        fclose($pipes[1]);
        $exit = $this->waitExit($proc);
        $err = $this->readAndRemove($errFile);

        if ($exit !== 0) {
            throw new BackupException(sprintf('pg_dump thất bại (mã %d): %s', $exit, $this->summarize($err)));
        }
    }

    /**
     * Chạy pg_restore nạp vào $database; $feed nhận hàm ghi để đẩy dữ liệu vào stdin của pg_restore.
     * Dừng ở lỗi đầu tiên. $owner (nếu có) là role sở hữu các đối tượng được tạo.
     *
     * @param  callable(callable(string): void): void  $feed
     */
    public function streamRestore(string $database, ?string $owner, callable $feed): void
    {
        $c = $this->connection();

        $cmd = [
            $this->binary('pg_restore'), '--exit-on-error', '--no-owner', '--no-acl', '--no-password',
            '--host='.$c['host'], '--port='.$c['port'], '--username='.$c['username'], '--dbname='.$database,
        ];

        if ($owner !== null) {
            $cmd[] = '--role='.$owner;
        }

        $outFile = $this->tempPath('.out');
        $errFile = $this->tempPath('.err');
        $proc = $this->open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes, $c);
        $closedEarly = false;

        try {
            $feed(function (string $bytes) use ($pipes, &$closedEarly): void {
                if ($closedEarly || ! $this->writeAll($pipes[0], $bytes)) {
                    $closedEarly = true; // pg_restore đã dừng (thường do lỗi): thôi ghi, lấy thông báo lỗi của nó bên dưới
                }
            });
        } catch (Throwable $e) {
            $this->kill($proc, $pipes);
            @unlink($outFile);
            @unlink($errFile);

            throw $e;
        }

        @fclose($pipes[0]);
        $exit = $this->waitExit($proc);
        @unlink($outFile);
        $err = $this->readAndRemove($errFile);

        if ($exit !== 0 || $closedEarly) {
            throw new BackupException(sprintf('pg_restore thất bại (mã %d): %s', $exit, $this->summarize($err)));
        }
    }

    public function tempPath(string $suffix): string
    {
        $dir = storage_path('app/backup-tmp');

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir.DIRECTORY_SEPARATOR.bin2hex(random_bytes(12)).$suffix;
    }

    // --- nội bộ ---------------------------------------------------------------------------

    private function binary(string $tool): string
    {
        $exe = $tool.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        $dir = config('backup.platform.pg_bin');

        if (is_string($dir) && $dir !== '') {
            $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$exe;

            if (! is_file($path)) {
                throw new BackupException("Không tìm thấy {$exe} trong thư mục BACKUP_PG_BIN.");
            }

            return $path;
        }

        return $exe; // tìm trong PATH
    }

    /**
     * @param  list<string>  $cmd
     * @param  array<int, mixed>  $spec
     * @param  array<int, resource>  $pipes
     * @param  array{password: string, sslmode: ?string}  $c
     * @return resource
     */
    private function open(array $cmd, array $spec, &$pipes, array $c)
    {
        $env = array_merge(getenv(), ['PGPASSWORD' => $c['password'], 'PGCONNECT_TIMEOUT' => '15']);

        if ($c['sslmode'] !== null) {
            $env['PGSSLMODE'] = $c['sslmode'];
        }

        $proc = @proc_open($cmd, $spec, $pipes, null, $env, ['bypass_shell' => true]);

        if (! is_resource($proc)) {
            throw new BackupException('Không chạy được '.basename($cmd[0]).' (kiểm tra BACKUP_PG_BIN hoặc PATH của máy chủ).');
        }

        return $proc;
    }

    /** @param resource $proc */
    private function waitExit($proc): int
    {
        while (true) {
            $status = proc_get_status($proc);

            if (! $status['running']) {
                $code = (int) $status['exitcode'];
                break;
            }

            usleep(100_000);
        }

        proc_close($proc);

        return $code;
    }

    /**
     * @param  resource  $proc
     * @param  array<int, resource>  $pipes
     */
    private function kill($proc, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        @proc_terminate($proc);
        @proc_close($proc);
    }

    /** @param resource $pipe */
    private function writeAll($pipe, string $bytes): bool
    {
        $length = strlen($bytes);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($pipe, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                return false;
            }

            $offset += $written;
        }

        return true;
    }

    private function readAndRemove(string $path): string
    {
        $text = is_file($path) ? (string) file_get_contents($path) : '';
        @unlink($path);

        return $text;
    }

    /** Một dòng ngắn đủ để chẩn đoán, không đổ nguyên stderr vào bản ghi. */
    private function summarize(string $stderr): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $stderr) ?: [])));

        foreach ($lines as $line) {
            if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) {
                return $this->clip($line, 300);
            }
        }

        return $this->clip($lines[0] ?? 'không có thông báo', 300);
    }

    private function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    public static function assertIdentifier(string $name, string $what): void
    {
        if (! preg_match('/^[a-z_][a-z0-9_]{2,62}$/', $name)) {
            throw new BackupException("Tên {$what} không hợp lệ (chỉ a-z, 0-9, _; 3–63 ký tự).");
        }
    }
}
