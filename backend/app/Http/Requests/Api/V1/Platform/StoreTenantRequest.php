<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Rules\UniqueEmail;
use App\Rules\VietnamesePhone;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Onboarding doanh nghiệp mới. Quyền do middleware platform.admin kiểm tra. */
class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant' => ['required', 'array'],
            'tenant.name' => ['required', 'string', 'max:255'],
            'tenant.slug' => [
                'nullable', 'string', 'max:50', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(config('platform.reserved_slugs')),
                Rule::unique('tenants', 'slug'), // gồm cả Tenant đã xóa mềm (chỉ mục duy nhất của DB cũng tính)
            ],
            'tenant.phone' => ['nullable', 'string', 'regex:/^[0-9+\-\s().]{6,30}$/'],
            'tenant.address' => ['nullable', 'string', 'max:255'],
            'tenant.tax_code' => ['nullable', 'string', 'regex:/^\d{10}(-\d{3})?$/'],

            'owner' => ['required', 'array'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'string', 'email', 'max:255', new UniqueEmail],
            // Doanh nghiệp mới chưa có tài khoản nào nên số điện thoại không thể trùng (chỉ cần duy nhất trong doanh nghiệp).
            'owner.phone' => ['nullable', 'string', new VietnamesePhone],
            // Bỏ trống => hệ thống sinh mật khẩu tạm (hiển thị một lần). Dù cách nào, Owner cũng phải đổi ở lần đăng nhập đầu.
            'owner.password' => array_merge(['nullable'], PasswordPolicy::rules(fn () => $this->input('owner.email'))),

            'branch' => ['nullable', 'array'],
            'branch.code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'branch.name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tenant.name' => 'tên doanh nghiệp', 'tenant.slug' => 'định danh (slug)', 'tenant.tax_code' => 'mã số thuế',
            'owner.name' => 'tên chủ doanh nghiệp', 'owner.email' => 'email chủ doanh nghiệp', 'owner.password' => 'mật khẩu',
        ];
    }
}
