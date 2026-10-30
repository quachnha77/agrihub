<?php

namespace App\Services\Audit;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditSuppression;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ghi audit log. Gọi trong cùng transaction với nghiệp vụ để log và dữ liệu cùng thành công hoặc cùng bị hủy.
 *
 * Nguyên tắc: không bao giờ truyền mật khẩu/token vào đây; Redactor là lớp bảo vệ thứ hai chứ không thay thế điều đó.
 */
class AuditLogger
{
    public const SUCCESS = 'success';

    public const FAILURE = 'failure';

    public const DENIED = 'denied';

    public function __construct(
        private TenantContext $context,
        private AuditPartitions $partitions,
        private Redactor $redactor,
    ) {}

    /**
     * @param  string  $module  khu vực chức năng: auth, tenant, branch, user, rbac, ...
     * @param  string  $action  hành động: login, created, role_permissions_changed, ...
     * @param  array<string, mixed>|null  $before  trạng thái trước (chỉ những trường liên quan)
     * @param  array<string, mixed>|null  $after  trạng thái sau
     * @param  User|false|null  $actor  false = tự nhận từ người đang đăng nhập; null = không có (hệ thống/ẩn danh)
     */
    public function record(
        string $module,
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        string $result = self::SUCCESS,
        ?string $reason = null,
        array $metadata = [],
        ?int $tenantId = null,
        ?int $branchId = null,
        User|false|null $actor = false,
    ): void {
        $actor = $actor === false ? $this->currentUser() : $actor;
        $request = $this->httpRequest();

        $tenantId ??= $subject instanceof Tenant ? $subject->id : ($subject?->getAttribute('tenant_id'));
        $tenantId ??= $actor?->tenant_id ?? $this->context->tenantId();

        $branchId ??= $subject instanceof Branch ? $subject->id : ($subject?->getAttribute('branch_id'));
        $branchLabel = $subject instanceof Branch ? $subject->auditLabel() : null;

        [$subjectType, $subjectId, $subjectLabel] = $this->describe($subject);

        $row = [
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'branch_label' => $this->cut($branchLabel, 255),
            'actor_type' => $actor ? 'user' : ($request ? 'anonymous' : 'system'),
            'actor_id' => $actor?->id,
            'actor_uuid' => $actor?->uuid,
            'actor_label' => $actor ? $this->cut($actor->name.' <'.$actor->email.'>', 255) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $this->cut($request?->userAgent(), 500),
            'request_id' => $this->cut($request?->attributes->get('request_id'), 64),
            'module' => $this->cut($module, 50),
            'action' => $this->cut($action, 80),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'result' => $result,
            'reason' => $this->cut($reason, 255),
            'before' => $this->json($before),
            'after' => $this->json($after),
            'metadata' => $this->json($metadata === [] ? null : $metadata),
            'occurred_at' => Carbon::now('UTC')->format('Y-m-d H:i:s.u'),
        ];

        $this->insert($row);
    }

    /** Ghi sự kiện tự động cho model dùng trait Auditable (created / updated / deleted / restored). */
    public function modelEvent(Model $model, string $event): void
    {
        if (AuditSuppression::active()) {
            return;
        }

        $ignored = array_merge(config('audit.ignore_attributes'), method_exists($model, 'auditIgnored') ? $model->auditIgnored() : []);
        $hidden = $model->getHidden();

        $clean = function (array $attributes) use ($ignored, $hidden): array {
            $out = [];
            foreach ($attributes as $key => $value) {
                if (in_array($key, $ignored, true)) {
                    continue;
                }
                $out[$key] = in_array($key, $hidden, true) ? Redactor::MASK : $value;
            }

            return $out;
        };

        $before = $after = null;

        switch ($event) {
            case 'created':
                $after = $clean($model->getAttributes());
                break;
            case 'updated':
                $changes = $clean($model->getChanges());
                if ($changes === []) {
                    return; // chỉ đổi các trường bị bỏ qua (vd. last_login_at)
                }
                $before = $clean(array_intersect_key($model->getOriginal(), $changes));
                $after = $changes;
                break;
            case 'deleted':
                $before = $clean($model->getAttributes());
                break;
        }

        $this->record(
            module: method_exists($model, 'auditModule') ? $model->auditModule() : Str::snake(class_basename($model)),
            action: $event,
            subject: $model,
            before: $before,
            after: $after,
        );
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function describe(?Model $subject): array
    {
        if ($subject === null) {
            return [null, null, null];
        }

        $label = method_exists($subject, 'auditLabel')
            ? $subject->auditLabel()
            : ($subject->getAttribute('name') ?? $subject->getAttribute('email') ?? $subject->getAttribute('code'));

        return [
            $this->cut(Str::snake(class_basename($subject)), 80),
            $this->cut((string) ($subject->getAttribute('uuid') ?? $subject->getKey()), 64),
            $this->cut($label, 255),
        ];
    }

    private function currentUser(): ?User
    {
        // hasUser(): không kích hoạt việc nạp user từ session chỉ vì cần ghi log.
        return Auth::hasUser() && Auth::user() instanceof User ? Auth::user() : null;
    }

    /** Request HTTP đang xử lý (null khi chạy lệnh console/queue). */
    private function httpRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request->route() !== null ? $request : null;
    }

    private function json(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        return json_encode(
            $this->redactor->redact($data),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ) ?: null;
    }

    private function cut(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr(str_replace("\0", '', $value), 0, $max);
    }

    /** @param  array<string, mixed>  $row */
    private function insert(array $row): void
    {
        $write = function () use ($row) {
            try {
                DB::transaction(fn () => DB::table('audit_logs')->insert($row));
            } catch (QueryException $e) {
                // Chưa có phân vùng cho tháng này: tạo rồi thử lại một lần (savepoint giữ transaction ngoài còn dùng được).
                if ((string) $e->getCode() !== '23514' || ! str_contains($e->getMessage(), 'no partition')) {
                    throw $e;
                }

                $this->partitions->ensure(Carbon::parse($row['occurred_at'], 'UTC'));
                DB::transaction(fn () => DB::table('audit_logs')->insert($row));
            }
        };

        // Chỉ chuyển sang chế độ nền tảng khi context hiện tại không đủ quyền ghi hàng này (RLS WITH CHECK).
        $sameTenant = $row['tenant_id'] !== null && $this->context->tenantId() === (int) $row['tenant_id'];

        if ($this->context->isPlatform() || $sameTenant) {
            $write();
        } else {
            $this->context->runAsPlatform($write);
        }
    }
}
