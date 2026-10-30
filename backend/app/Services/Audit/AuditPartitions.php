<?php

namespace App\Services\Audit;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Quản lý phân vùng theo tháng (UTC) của bảng audit_logs. Tên phân vùng: audit_logs_yYYYYmMM. */
class AuditPartitions
{
    private const PATTERN = '/^audit_logs_y(\d{4})m(\d{2})$/';

    public function nameFor(CarbonInterface $month): string
    {
        $m = Carbon::instance($month)->utc();

        return sprintf('audit_logs_y%sm%s', $m->format('Y'), $m->format('m'));
    }

    public function exists(string $name): bool
    {
        return DB::selectOne('select to_regclass(?) as oid', ['public.'.$name])->oid !== null;
    }

    /** Tạo phân vùng cho tháng chứa $month nếu chưa có; trả về tên phân vùng. */
    public function ensure(CarbonInterface $month): string
    {
        $start = Carbon::instance($month)->utc()->startOfMonth();
        $end = $start->copy()->addMonth();
        $name = $this->nameFor($start);

        if ($this->exists($name)) {
            return $name;
        }

        DB::transaction(function () use ($name, $start, $end) {
            DB::unprepared(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF audit_logs FOR VALUES FROM ('%s') TO ('%s')",
                $name, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'),
            ));

            $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
                ." OR current_setting('app.is_platform', true) = 'on')";

            DB::unprepared("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
            DB::unprepared("DROP POLICY IF EXISTS tenant_isolation ON {$name}");
            DB::unprepared("CREATE POLICY tenant_isolation ON {$name} USING {$predicate} WITH CHECK {$predicate}");
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}_no_truncate ON {$name}");
            DB::unprepared("CREATE TRIGGER {$name}_no_truncate BEFORE TRUNCATE ON {$name} FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_immutable()");
        });

        return $name;
    }

    /**
     * Đảm bảo có phân vùng từ tháng hiện tại đến $months tháng tới.
     *
     * @return list<string> tên các phân vùng vừa được tạo
     */
    public function ensureAhead(int $months): array
    {
        $created = [];
        $month = Carbon::now('UTC')->startOfMonth();

        for ($i = 0; $i <= $months; $i++) {
            $target = $month->copy()->addMonths($i);
            if (! $this->exists($this->nameFor($target))) {
                $created[] = $this->ensure($target);
            }
        }

        return $created;
    }

    /** @return list<string> tên các phân vùng theo tháng hiện có, cũ → mới */
    public function all(): array
    {
        $names = collect(DB::select(
            "select c.relname from pg_inherits i
             join pg_class c on c.oid = i.inhrelid
             join pg_class p on p.oid = i.inhparent
             where p.relname = 'audit_logs'",
        ))->pluck('relname')->filter(fn ($n) => preg_match(self::PATTERN, $n))->sort()->values();

        return $names->all();
    }

    /** Tháng (đầu tháng, UTC) mà phân vùng đại diện. */
    public function monthOf(string $name): ?Carbon
    {
        return preg_match(self::PATTERN, $name, $m)
            ? Carbon::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'UTC')
            : null;
    }

    /**
     * Xóa vĩnh viễn các phân vùng của tháng nằm hoàn toàn trước mốc retention.
     * Chỉ xóa nguyên phân vùng (DROP), không bao giờ xóa từng dòng.
     *
     * @return list<string> tên các phân vùng bị (hoặc sẽ bị, nếu dry-run) xóa
     */
    public function purge(int $retentionMonths, bool $dryRun = false): array
    {
        $cutoff = Carbon::now('UTC')->startOfMonth()->subMonths($retentionMonths);
        $dropped = [];

        foreach ($this->all() as $name) {
            $month = $this->monthOf($name);

            if ($month !== null && $month->lt($cutoff)) {
                if (! $dryRun) {
                    DB::unprepared("DROP TABLE {$name}");
                }
                $dropped[] = $name;
            }
        }

        return $dropped;
    }
}
