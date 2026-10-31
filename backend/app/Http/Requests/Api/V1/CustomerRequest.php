<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Branch;
use App\Models\Customer;
use App\Rules\VietnamesePhone;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tạo (POST) và sửa (PATCH) khách hàng. Quyền do CustomerPolicy kiểm tra ở controller.
 *
 * - `code` để trống khi tạo thì hệ thống tự sinh (KH0001...).
 * - `branch_id` là chi nhánh phụ trách; khi sửa, gửi `branch_id` khác là chuyển khách sang chi nhánh đó.
 * - `addresses` và `contacts` là TOÀN BỘ danh sách: gửi lên là thay nguyên khối.
 */
class CustomerRequest extends FormRequest
{
    /** Người không có quyền nhận 403 ngay, trước cả bước kiểm tra dữ liệu. Phạm vi chi nhánh cụ thể do CustomerPolicy kiểm tra ở controller. */
    public function authorize(): bool
    {
        return $this->user()?->can($this->creating() ? 'crm.customer.create' : 'crm.customer.update') ?? false;
    }

    private function creating(): bool
    {
        return $this->isMethod('POST');
    }

    private function customer(): ?Customer
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer ? $customer : null;
    }

    /** Chi nhánh phụ trách được chọn trong yêu cầu (đã kiểm tra tồn tại và đang hoạt động); null nếu không gửi. */
    public function targetBranch(): ?Branch
    {
        $uuid = $this->validated('branch_id');

        return is_string($uuid) ? Branch::where('uuid', $uuid)->firstOrFail() : null;
    }

    public function rules(): array
    {
        $creating = $this->creating();
        $presence = $creating ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'branch_id' => [$presence, 'uuid', Rule::exists('branches', 'uuid')->where('tenant_id', $tenantId)->where('status', 'active')->whereNull('deleted_at')],
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $this->uniqueCode()],
            'type' => [$presence, Rule::in(Customer::TYPES)],
            'name' => [$presence, 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', new VietnamesePhone],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'tax_code' => ['nullable', 'string', 'regex:/^[0-9][0-9-]{7,19}$/'],
            'group_id' => ['nullable', 'uuid', Rule::exists('customer_groups', 'uuid')->where('tenant_id', $tenantId)],
            'credit_limit' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in([Customer::STATUS_ACTIVE, Customer::STATUS_INACTIVE])],
            'addresses' => ['sometimes', 'array', 'max:10'],
            'addresses.*.label' => ['nullable', 'string', 'max:50'],
            'addresses.*.address' => ['required', 'string', 'max:255'],
            'addresses.*.ward' => ['nullable', 'string', 'max:100'],
            'addresses.*.district' => ['nullable', 'string', 'max:100'],
            'addresses.*.province' => ['nullable', 'string', 'max:100'],
            'addresses.*.is_default' => ['sometimes', 'boolean'],
            'contacts' => ['sometimes', 'array', 'max:10'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30', new VietnamesePhone],
            'contacts.*.position' => ['nullable', 'string', 'max:100'],
            'contacts.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Mã khách duy nhất trong doanh nghiệp, không phân biệt hoa/thường (khách đã xóa mềm không giữ mã). */
    private function uniqueCode(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $taken = Customer::whereRaw('lower(code) = ?', [mb_strtolower($value)])
                ->when($this->customer(), fn ($q, Customer $own) => $q->where('id', '!=', $own->id))
                ->exists();

            if ($taken) {
                $fail('Mã khách hàng này đã được dùng.');
            }
        };
    }

    /** Mỗi khách có tối đa một địa chỉ mặc định. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $defaults = collect($this->input('addresses', []))->filter(fn ($row) => is_array($row) && ! empty($row['is_default']))->count();

            if ($defaults > 1) {
                $validator->errors()->add('addresses', 'Chỉ được chọn một địa chỉ mặc định.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'branch_id' => 'chi nhánh phụ trách', 'code' => 'mã khách hàng', 'type' => 'loại khách', 'name' => 'tên khách hàng', 'phone' => 'số điện thoại',
            'email' => 'email', 'tax_code' => 'mã số thuế', 'group_id' => 'nhóm khách', 'credit_limit' => 'hạn mức công nợ', 'credit_days' => 'số ngày nợ tối đa',
            'notes' => 'ghi chú', 'status' => 'trạng thái', 'addresses' => 'danh sách địa chỉ', 'addresses.*.label' => 'tên địa chỉ', 'addresses.*.address' => 'địa chỉ',
            'addresses.*.ward' => 'phường/xã', 'addresses.*.district' => 'quận/huyện', 'addresses.*.province' => 'tỉnh/thành',
            'contacts' => 'danh sách người liên hệ', 'contacts.*.name' => 'tên người liên hệ', 'contacts.*.phone' => 'số điện thoại người liên hệ',
            'contacts.*.position' => 'chức vụ', 'contacts.*.note' => 'ghi chú',
        ];
    }
}
