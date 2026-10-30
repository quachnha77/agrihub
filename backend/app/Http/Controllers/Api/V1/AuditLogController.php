<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Xem audit log (chỉ đọc). Tenant chỉ thấy log của Tenant mình (RLS) và theo phạm vi quyền `audit.log.view`;
 * Platform Admin xem được toàn bộ, kể cả sự kiện cấp nền tảng (không thuộc Tenant nào).
 * Phân trang bằng cursor (bảng lớn, sắp theo thời gian mới → cũ). Thời gian lọc tính theo UTC.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('audit.log.view');

        $user = $request->user();
        $query = AuditLog::query();

        // Phạm vi: tenant = mọi log của Tenant (RLS đã giới hạn); branch = log gắn với chi nhánh của mình; own = hành động của mình.
        match ($user->permissionScope('audit.log.view')) {
            'tenant' => null,
            'branch' => $query->whereIn('branch_id', $user->branchIds()),
            default => $query->where('actor_id', $user->getKey()),
        };

        return $this->respond($request, $query, platform: false);
    }

    public function platformIndex(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['tenant' => ['nullable', 'string', 'max:40']]);
        $query = AuditLog::query();

        if (! empty($validated['tenant'])) {
            if ($validated['tenant'] === 'none') {
                $query->whereNull('tenant_id'); // sự kiện cấp nền tảng
            } else {
                $query->where('tenant_id', Tenant::withTrashed()->where('uuid', $validated['tenant'])->value('id') ?? 0);
            }
        }

        return $this->respond($request, $query, platform: true);
    }

    private function respond(Request $request, Builder $query, bool $platform): AnonymousResourceCollection
    {
        $f = $request->validate([
            'module' => ['nullable', 'string', 'max:50'],
            'action' => ['nullable', 'string', 'max:80'],
            'result' => ['nullable', Rule::in(['success', 'failure', 'denied'])],
            'actor' => ['nullable', 'uuid'],
            'branch' => ['nullable', 'uuid'],
            'subject_type' => ['nullable', 'string', 'max:80'],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query
            ->when($f['module'] ?? null, fn ($q, $v) => $q->where('module', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['result'] ?? null, fn ($q, $v) => $q->where('result', $v))
            ->when($f['actor'] ?? null, fn ($q, $v) => $q->where('actor_uuid', $v))
            ->when($f['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($f['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%';
                $q->where(fn ($w) => $w->where('actor_label', 'ilike', $like)->orWhere('subject_label', 'ilike', $like));
            });

        if (! empty($f['branch'])) {
            $query->where('branch_id', Branch::where('uuid', $f['branch'])->value('id') ?? 0);
        }

        if (! empty($f['from'])) {
            $query->where('occurred_at', '>=', Carbon::parse($f['from'], 'UTC')->utc()->format('Y-m-d H:i:s'));
        }
        if (! empty($f['to'])) {
            $to = Carbon::parse($f['to'], 'UTC')->utc();
            // "2026-10-30" (chỉ có ngày) nghĩa là hết ngày đó.
            $to = strlen((string) $f['to']) <= 10 ? $to->endOfDay() : $to;
            $query->where('occurred_at', '<=', $to->format('Y-m-d H:i:s.u'));
        }

        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate($f['per_page'] ?? 25)->withQueryString(); // link trang kế giữ nguyên bộ lọc

        if ($platform) {
            $tenants = Tenant::withTrashed()->whereIn('id', $page->pluck('tenant_id')->filter()->unique())->get()->keyBy('id');
            $page->getCollection()->each(fn (AuditLog $log) => $log->tenant_info = $log->tenant_id && isset($tenants[$log->tenant_id])
                ? ['id' => $tenants[$log->tenant_id]->uuid, 'name' => $tenants[$log->tenant_id]->name]
                : null);
        }

        return AuditLogResource::collection($page)->additional(['platform' => $platform]);
    }
}
