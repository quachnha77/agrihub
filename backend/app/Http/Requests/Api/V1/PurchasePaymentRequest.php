<?php

namespace App\Http\Requests\Api\V1;

use App\Models\PurchasePayment;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ghi một lần trả tiền nhà cung cấp (POST /purchases/{id}/payments). Không vượt số còn nợ: PurchaseService kiểm tra (khóa dòng phiếu). */
class PurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase.order.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999'],
            'method' => ['required', Rule::in(PurchasePayment::METHODS)],
            'paid_on' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.Carbon::now(config('inventory.timezone'))->toDateString()],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['amount' => 'số tiền', 'method' => 'hình thức trả', 'paid_on' => 'ngày trả', 'note' => 'ghi chú'];
    }
}
