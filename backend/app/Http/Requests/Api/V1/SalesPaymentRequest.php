<?php

namespace App\Http\Requests\Api\V1;

use App\Models\SalesPayment;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Thu thêm tiền của khách (POST /sales/{id}/payments). Không vượt số còn nợ: SalesService kiểm tra (khóa dòng phiếu). */
class SalesPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.order.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999'],
            'method' => ['required', Rule::in(SalesPayment::METHODS)],
            'paid_on' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.Carbon::now(config('inventory.timezone'))->toDateString()],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['amount' => 'số tiền', 'method' => 'hình thức thu', 'paid_on' => 'ngày thu', 'note' => 'ghi chú'];
    }
}
