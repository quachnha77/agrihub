<?php

namespace Tests\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait ReadsAuditLog
{
    /**
     * Đọc audit log ở chế độ nền tảng (thấy mọi Tenant), kèm JSON đã giải mã.
     *
     * @param  array<string, mixed>  $where
     * @return Collection<int, object>
     */
    protected function auditRows(array $where = []): Collection
    {
        return $this->platform(fn () => DB::table('audit_logs')->where($where)->orderBy('id')->get()->map(function ($row) {
            foreach (['before', 'after', 'metadata'] as $column) {
                $row->{$column} = $row->{$column} === null ? null : json_decode($row->{$column}, true);
            }

            return $row;
        }));
    }

    protected function lastAudit(array $where = []): ?object
    {
        return $this->auditRows($where)->last();
    }

    protected function auditCount(array $where = []): int
    {
        return $this->auditRows($where)->count();
    }
}
