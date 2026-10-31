<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomerRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Services\Crm\CustomerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Khách hàng dùng chung toàn doanh nghiệp, mỗi khách có một chi nhánh phụ trách. Người dùng chỉ thấy/sửa khách thuộc các chi nhánh trong phạm vi quyền
 * `crm.customer.*` (Policy). Route-model-binding đi qua TenantScope nên khách của doanh nghiệp khác trả 404.
 */
class CustomerController extends Controller
{
    private const WITH = ['branch', 'group', 'addresses', 'contacts'];

    public function __construct(private CustomerService $customers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(Customer::TYPES)],
            'status' => ['nullable', Rule::in([Customer::STATUS_ACTIVE, Customer::STATUS_INACTIVE])],
            'branch' => ['nullable', 'uuid'],
            'group' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->visible($request)->with(self::WITH)->orderBy('name')->orderBy('id');

        if (! empty($f['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
            $digits = preg_replace('/\D/', '', $f['q']);

            $query->where(function (Builder $q) use ($like, $digits) {
                $q->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like);

                // Số điện thoại lưu dạng chuẩn (0912345678): so theo chữ số để gõ kiểu nào cũng tìm được.
                if (strlen($digits) >= 3) {
                    $q->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        $query->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['branch'] ?? null, fn ($q, $v) => $q->whereIn('branch_id', Branch::where('uuid', $v)->select('id')))
            ->when($f['group'] ?? null, fn ($q, $v) => $q->whereIn('group_id', CustomerGroup::where('uuid', $v)->select('id')));

        return CustomerResource::collection($query->paginate($f['per_page'] ?? 20)->withQueryString());
    }

    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return new CustomerResource($customer->load(self::WITH));
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $branch = $request->targetBranch();
        Gate::authorize('create', [Customer::class, $branch->id]);

        return (new CustomerResource($this->customers->create($branch, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        Gate::authorize('update', $customer);

        // Chuyển sang chi nhánh khác: người sửa phải có quyền ở chi nhánh đích (quản lý chi nhánh không "đẩy" khách ra ngoài phạm vi của mình).
        $newBranch = $request->targetBranch();

        if ($newBranch !== null && $newBranch->id !== $customer->branch_id) {
            Gate::authorize('create', [Customer::class, $newBranch->id]);
        }

        return new CustomerResource($this->customers->update($customer, $request->validated(), $newBranch));
    }

    public function destroy(Customer $customer): Response
    {
        Gate::authorize('delete', $customer);

        $this->customers->delete($customer);

        return response()->noContent();
    }

    /** Khách trong phạm vi quyền xem: `tenant` = mọi chi nhánh, `branch` = các chi nhánh được gán. */
    private function visible(Request $request): Builder
    {
        $query = Customer::query();

        if ($request->user()->permissionScope('crm.customer.view') !== 'tenant') {
            $query->whereIn('branch_id', $request->user()->branchIds());
        }

        return $query;
    }
}
