<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Supplier;
use App\Rules\VietnamesePhone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tạo (POST) và sửa (PATCH) nhà cung cấp. `code` để trống khi tạo thì tự sinh (NCC0001...); `contacts` là toàn bộ danh sách. */
class SupplierRequest extends FormRequest
{
    /** Người không có quyền nhận 403 ngay, trước cả bước kiểm tra dữ liệu. */
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'purchase.supplier.create' : 'purchase.supplier.update') ?? false;
    }

    private function supplier(): ?Supplier
    {
        $supplier = $this->route('supplier');

        return $supplier instanceof Supplier ? $supplier : null;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $this->uniqueCode()],
            'name' => [$presence, 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'regex:/^[0-9][0-9-]{7,19}$/'],
            'phone' => ['nullable', 'string', 'max:30', new VietnamesePhone],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in([Supplier::STATUS_ACTIVE, Supplier::STATUS_INACTIVE])],
            'contacts' => ['sometimes', 'array', 'max:10'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30', new VietnamesePhone],
            'contacts.*.email' => ['nullable', 'string', 'email', 'max:255'],
            'contacts.*.position' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function uniqueCode(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $taken = Supplier::whereRaw('lower(code) = ?', [mb_strtolower($value)])
                ->when($this->supplier(), fn ($q, Supplier $own) => $q->where('id', '!=', $own->id))
                ->exists();

            if ($taken) {
                $fail('Mã nhà cung cấp này đã được dùng.');
            }
        };
    }

    public function attributes(): array
    {
        return [
            'code' => 'mã nhà cung cấp', 'name' => 'tên nhà cung cấp', 'tax_code' => 'mã số thuế', 'phone' => 'số điện thoại', 'email' => 'email', 'address' => 'địa chỉ',
            'payment_terms_days' => 'số ngày được chịu', 'notes' => 'ghi chú', 'status' => 'trạng thái', 'contacts' => 'danh sách người liên hệ',
            'contacts.*.name' => 'tên người liên hệ', 'contacts.*.phone' => 'số điện thoại người liên hệ', 'contacts.*.email' => 'email người liên hệ', 'contacts.*.position' => 'chức vụ',
        ];
    }
}
