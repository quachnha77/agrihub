<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** Hủy một lần trả tiền (POST /purchases/{id}/payments/{payment}/void): bắt buộc nêu lý do. */
class VoidPurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase.order.cancel') ?? false;
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
