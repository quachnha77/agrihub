<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SyncUserBranchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_ids' => ['present', 'array', 'max:50'],
            'branch_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
