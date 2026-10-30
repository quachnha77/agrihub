<?php

namespace Tests\Concerns;

use App\Services\Backup\PgTools;
use Illuminate\Support\Facades\Storage;
use PDO;
use Throwable;

/**
 * Dựng một database NGUỒN riêng (đã commit, có RLS FORCE, phân vùng, bảng phiên) để kiểm thử sao lưu toàn hệ thống.
 * Không dùng database test chính vì nó nằm trong transaction chưa commit mà pg_dump (kết nối khác) không nhìn thấy.
 *
 * Cần tài khoản sao lưu thật (BACKUP_DB_USERNAME/PASSWORD): CI luôn có; máy dev chưa cấu hình thì bỏ qua các test này.
 */
trait UsesPlatformDump
{
    protected string $srcDb = '';

    /** @var list<string> database phụ phải dọn khi test kết thúc */
    protected array $extraDbs = [];

    protected function setUpPlatformDump(): void
    {
        if (! config('backup.platform.username')) {
            if (getenv('CI')) {
                $this->fail('CI phải cấu hình BACKUP_DB_USERNAME / BACKUP_DB_PASSWORD (xem .github/workflows/ci.yml).');
            }

            $this->markTestSkipped('Chưa cấu hình BACKUP_DB_USERNAME / BACKUP_DB_PASSWORD (xem docs/TESTING.md).');
        }

        Storage::fake('backups');
        config(['backup.disk' => 'backups']);

        $pg = app(PgTools::class);
        $this->srcDb = 'agrihub_t_src_'.bin2hex(random_bytes(4));
        $pg->pdo()->exec('CREATE DATABASE '.$this->srcDb);

        $pdo = $pg->pdo($this->srcDb);
        $pdo->exec(<<<'SQL'
            CREATE TABLE items (id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, name text NOT NULL, meta jsonb);
            ALTER TABLE items ENABLE ROW LEVEL SECURITY;
            ALTER TABLE items FORCE ROW LEVEL SECURITY;
            CREATE POLICY tenant_isolation ON items USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint);
            CREATE TABLE notes (id bigserial PRIMARY KEY, item_id bigint NOT NULL REFERENCES items(id), body text);
            CREATE TABLE sessions (id text PRIMARY KEY, payload text);
            CREATE TABLE migrations (id serial PRIMARY KEY, migration varchar(255) NOT NULL, batch int NOT NULL);
            CREATE TABLE events (id bigserial, at date NOT NULL, kind text, PRIMARY KEY (id, at)) PARTITION BY RANGE (at);
            CREATE TABLE events_2026_10 PARTITION OF events FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');
            CREATE TABLE events_2026_11 PARTITION OF events FOR VALUES FROM ('2026-11-01') TO ('2026-12-01');

            INSERT INTO items (tenant_id, name, meta) VALUES
                (1, 'Phân NPK Ư', '{"ghi_chú": "Đạm cao"}'), (1, 'Thuốc sâu', NULL), (2, 'Hạt giống lúa', '{"vụ": "đông xuân"}');
            INSERT INTO notes (item_id, body) VALUES (1, 'ghi chú 1'), (3, 'ghi chú 2');
            INSERT INTO sessions VALUES ('s1', 'phiên đăng nhập cũ'), ('s2', 'phiên khác');
            INSERT INTO migrations (migration, batch) VALUES ('2026_01_01_000000_a', 1), ('2026_02_01_000000_b', 1);
            INSERT INTO events (at, kind) VALUES ('2026-10-05', 'x'), ('2026-10-20', 'y'), ('2026-11-02', 'z');
            SQL);
        unset($pdo);

        config(['backup.platform.database' => $this->srcDb]);
    }

    protected function tearDownPlatformDump(): void
    {
        if ($this->srcDb === '') {
            return;
        }

        $pg = app(PgTools::class);

        try {
            $admin = $pg->pdo(config('database.connections.'.config('database.default').'.database'));
            foreach (array_merge([$this->srcDb], $this->extraDbs) as $db) {
                $admin->exec('DROP DATABASE IF EXISTS '.$pg->quote($db).' WITH (FORCE)');
            }
        } catch (Throwable) {
            // dọn dẹp tối đa; lỗi ở đây không được che lỗi của chính test
        }
    }

    /** @return list<string> các database tạm kiểm thử khôi phục còn sót lại */
    protected function leftoverScratchDatabases(): array
    {
        $prefix = (string) config('backup.platform.verify_prefix');
        $stmt = app(PgTools::class)->pdo()->prepare('SELECT datname FROM pg_database WHERE datname LIKE ?');
        $stmt->execute([str_replace('_', '\_', $prefix).'%']);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function newDbName(): string
    {
        return $this->extraDbs[] = 'agrihub_t_dr_'.bin2hex(random_bytes(4));
    }

    protected function sourceCount(string $table): int
    {
        return (int) app(PgTools::class)->pdo($this->srcDb)->query("SELECT count(*) FROM {$table}")->fetchColumn();
    }
}
