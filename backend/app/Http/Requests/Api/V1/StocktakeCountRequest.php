<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ghi số đếm của một dòng (PATCH /stocktakes/{id}/lines/{line}). `quantity` theo ĐƠN VỊ `unit_id` người dùng chọn (có thể là đơn vị lớn như Bao); đếm 0 là hợp lệ.
 * `quantity` = null xóa số đếm (đưa dòng về "chưa đếm"). Việc đơn vị có thuộc sản phẩm không do StocktakeService kiểm tra.
 */
class StocktakeCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.adjustment.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['present', 'nullable', 'numeric', 'decimal:0,3', 'between:0,99999999'],
            'unit_id' => ['required_with:quantity', 'nullable', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return ['quantity' => 'số đếm', 'unit_id' => 'đơn vị'];
    }
}
