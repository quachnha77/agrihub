<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** Hủy một lần thu tiền (POST /sales/{id}/payments/{payment}/void): bắt buộc nêu lý do. */
class VoidSalesPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.order.cancel') ?? false;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:2', 'max:255']];
    }

    public function attributes(): array
    {
        return ['reason' => 'lý do'];
    }
}
