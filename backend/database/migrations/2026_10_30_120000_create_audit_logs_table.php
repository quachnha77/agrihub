<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Audit log: bảng chỉ ghi thêm (append-only), phân vùng theo tháng (UTC), có Row-Level Security theo Tenant.
 *
 * - Không có khóa ngoại: log phải tồn tại lâu hơn dữ liệu mà nó mô tả.
 * - Trigger chặn UPDATE/DELETE/TRUNCATE. Việc xóa theo retention thực hiện bằng DROP PARTITION (xem agrihub:audit-maintain).
 * - Hàng tenant_id NULL (sự kiện cấp nền tảng) chỉ thấy được ở chế độ nền tảng.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE SEQUENCE IF NOT EXISTS audit_logs_id_seq');

        DB::unprepared(<<<'SQL'
            CREATE TABLE audit_logs (
                id           bigint       NOT NULL DEFAULT nextval('audit_logs_id_seq'),
                uuid         uuid         NOT NULL DEFAULT gen_random_uuid(),
                tenant_id    bigint       NULL,
                branch_id    bigint       NULL,
                branch_label varchar(255) NULL,
                actor_type   varchar(12)  NOT NULL,
                actor_id     bigint       NULL,
                actor_uuid   uuid         NULL,
                actor_label  varchar(255) NULL,
                ip_address   varchar(45)  NULL,
                user_agent   varchar(500) NULL,
                request_id   varchar(64)  NULL,
                module       varchar(50)  NOT NULL,
                action       varchar(80)  NOT NULL,
                subject_type  varchar(80) NULL,
                subject_id    varchar(64) NULL,
                subject_label varchar(255) NULL,
                result       varchar(10)  NOT NULL,
                reason       varchar(255) NULL,
                "before"     jsonb        NULL,
                "after"      jsonb        NULL,
                metadata     jsonb        NULL,
                occurred_at  timestamp(6) NOT NULL DEFAULT timezone('UTC', now()),
                PRIMARY KEY (id, occurred_at),
                CONSTRAINT audit_logs_result_check CHECK (result IN ('success', 'failure', 'denied')),
                CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user', 'system', 'anonymous'))
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        DB::unprepared('ALTER SEQUENCE audit_logs_id_seq OWNED BY audit_logs.id');

        DB::unprepared('CREATE INDEX audit_logs_tenant_time_idx ON audit_logs (tenant_id, occurred_at DESC)');
        DB::unprepared('CREATE INDEX audit_logs_tenant_module_idx ON audit_logs (tenant_id, module, action, occurred_at DESC)');
        DB::unprepared('CREATE INDEX audit_logs_subject_idx ON audit_logs (tenant_id, subject_type, subject_id)');
        DB::unprepared('CREATE INDEX audit_logs_actor_idx ON audit_logs (actor_id, occurred_at DESC)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs là bảng chỉ ghi thêm (append-only): thao tác % bị cấm', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$
        SQL);
        DB::unprepared('CREATE TRIGGER audit_logs_no_update_delete BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_immutable()');
        DB::unprepared('CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_immutable()');

        $this->secure('audit_logs');

        // Phân vùng cho tháng hiện tại và 3 tháng tới (phần còn lại do agrihub:audit-maintain và cơ chế tự tạo khi thiếu).
        $month = Carbon::now('UTC')->startOfMonth();
        for ($i = 0; $i <= 3; $i++) {
            $start = $month->copy()->addMonths($i);
            $end = $start->copy()->addMonth();
            $name = sprintf('audit_logs_y%sm%s', $start->format('Y'), $start->format('m'));

            DB::unprepared(sprintf(
                "CREATE TABLE %s PARTITION OF audit_logs FOR VALUES FROM ('%s') TO ('%s')",
                $name, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'),
            ));
            $this->secure($name);
            DB::unprepared("CREATE TRIGGER {$name}_no_truncate BEFORE TRUNCATE ON {$name} FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_immutable()");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS audit_logs CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_immutable()');
        DB::unprepared('DROP SEQUENCE IF EXISTS audit_logs_id_seq');
    }

    private function secure(string $table): void
    {
        $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
            ." OR current_setting('app.is_platform', true) = 'on')";

        DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::unprepared("CREATE POLICY tenant_isolation ON {$table} USING {$predicate} WITH CHECK {$predicate}");
    }
};
