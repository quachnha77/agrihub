<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Platform\StoreTenantRequest;
use App\Http\Requests\Api\V1\Platform\UpdateTenantRequest;
use App\Http\Resources\Api\V1\Platform\TenantResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRevoker;
use App\Services\Platform\TenantOnboarding;
use App\Support\Audit\AuditSuppression;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Quản lý doanh nghiệp (Tenant) cho Platform Admin. Chạy ở chế độ nền tảng (middleware platform.admin).
 * Không có thao tác xóa: Tenant chỉ bị khóa, dữ liệu không bao giờ tự mất.
 */
class TenantController extends Controller
{
    public function __construct(
        private TenantOnboarding $onboarding,
        private SessionRevoker $sessions,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])],
            'sort' => ['nullable', Rule::in(['name', '-name', 'created_at', '-created_at'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sort = $f['sort'] ?? '-created_at';

        $page = Tenant::query()
            ->withCount(['users', 'branches'])
            ->with('subscription.plan')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%';
                $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('slug', 'ilike', $like)->orWhere('tax_code', 'ilike', $like));
            })
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate($f['per_page'] ?? 20)
            ->withQueryString();

        $this->attachOwners($page->getCollection());

        return TenantResource::collection($page);
    }

    public function show(Tenant $tenant): TenantResource
    {
        $tenant->loadCount(['users', 'branches'])->load(['branches', 'subscription.plan']);
        $this->attachOwners(new Collection([$tenant]));

        return new TenantResource($tenant);
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $result = $this->onboarding->onboard($request->user(), $request->validated());

        $tenant = $result['tenant'];
        $tenant->loadCount(['users', 'branches']);
        $tenant->setRelation('owners', new Collection([$result['owner']]));
        $tenant->setRelation('branches', new Collection([$result['branch']]));

        $resource = new TenantResource($tenant);

        if ($result['temporary_password'] !== null) {
            // Chỉ trả về MỘT lần ở đây; không lưu bản rõ ở đâu cả.
            $resource->additional(['temporary_password' => $result['temporary_password']]);
        }

        return $resource->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        $tenant->fill($request->validated())->save(); // Auditable ghi before/after

        return $this->show($tenant->refresh());
    }

    public function suspend(Request $request, Tenant $tenant): TenantResource
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        if (! $tenant->isActive()) {
            throw new ApiProblemException(409, 'tenant_already_suspended', 'Doanh nghiệp đã bị khóa.');
        }

        DB::transaction(function () use ($request, $tenant, $reason) {
            AuditSuppression::run(fn () => $tenant->forceFill([
                'status' => Tenant::STATUS_SUSPENDED,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ])->save());

            $revoked = $this->sessions->forTenant($tenant->id); // đăng xuất mọi người dùng của doanh nghiệp ngay lập tức

            $this->audit->record(
                'tenant', 'suspended', subject: $tenant, actor: $request->user(), reason: mb_substr($reason, 0, 255),
                before: ['status' => Tenant::STATUS_ACTIVE], after: ['status' => Tenant::STATUS_SUSPENDED],
                metadata: ['sessions_revoked' => $revoked],
            );
        });

        return $this->show($tenant->refresh());
    }

    public function activate(Request $request, Tenant $tenant): TenantResource
    {
        if ($tenant->isActive()) {
            throw new ApiProblemException(409, 'tenant_not_suspended', 'Doanh nghiệp không ở trạng thái bị khóa.');
        }

        DB::transaction(function () use ($request, $tenant) {
            $previousReason = $tenant->suspension_reason;

            AuditSuppression::run(fn () => $tenant->forceFill([
                'status' => Tenant::STATUS_ACTIVE,
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save());

            $this->audit->record(
                'tenant', 'activated', subject: $tenant, actor: $request->user(),
                before: ['status' => Tenant::STATUS_SUSPENDED], after: ['status' => Tenant::STATUS_ACTIVE],
                metadata: ['previous_suspension_reason' => $previousReason],
            );
        });

        return $this->show($tenant->refresh());
    }

    /**
     * Gắn danh sách Chủ doanh nghiệp (người giữ vai trò owner) vào từng Tenant bằng 1 truy vấn.
     *
     * @param  Collection<int, Tenant>  $tenants
     */
    private function attachOwners(Collection $tenants): void
    {
        $owners = User::query()
            ->whereIn('tenant_id', $tenants->modelKeys())
            ->whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))
            ->orderBy('name')
            ->get()
            ->groupBy('tenant_id');

        $tenants->each(fn (Tenant $t) => $t->setRelation('owners', $owners[$t->id] ?? new Collection));
    }
}
