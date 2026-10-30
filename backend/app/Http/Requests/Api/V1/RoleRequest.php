<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Rbac\Catalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tạo (POST) và cập nhật (PATCH) vai trò. Giới hạn leo thang quyền do RolePolicy/RoleController đảm nhiệm. */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => [$presence, 'array', 'max:500'],
            'permissions.*.key' => ['required', 'string', 'distinct', Rule::in(array_keys(Catalog::permissions()))],
            'permissions.*.scope' => ['required', 'string', Rule::in(array_keys(Catalog::SCOPES))],
        ];
    }

    /** @return array<string, string> permission key => scope */
    public function grants(): array
    {
        return collect($this->validated('permissions') ?? [])->pluck('scope', 'key')->all();
    }
}
