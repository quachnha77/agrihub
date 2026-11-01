<?php

namespace App\Http\Requests\Api\V1;

use App\Models\SalesPayment;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Khách trả hàng (POST /sales/{id}/returns): chọn dòng của phiếu bán và số lượng trả (theo đơn vị bán), ngày trả, ghi chú, hình thức hoàn tiền (chỉ dùng khi có tiền phải hoàn).
 * Chỉ kiểm tra HÌNH DẠNG; số lượng tối đa trả được và tiền trừ nợ / hoàn lại do SalesService tính (khóa dòng phiếu).
 */
class SalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.order.cancel') ?? false;
    }

    public function rules(): array
    {
        return [
            'returned_on' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.Carbon::now(config('inventory.timezone'))->toDateString()],
            'note' => ['nullable', 'string', 'max:500'],
            'refund_method' => ['nullable', Rule::in(SalesPayment::METHODS)],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:99999999'],
        ];
    }

    public function attributes(): array
    {
        return ['returned_on' => 'ngày trả hàng', 'note' => 'ghi chú', 'refund_method' => 'hình thức hoàn tiền', 'lines' => 'danh sách hàng trả', 'lines.*.line_id' => 'dòng hàng', 'lines.*.quantity' => 'số lượng trả'];
    }
}
