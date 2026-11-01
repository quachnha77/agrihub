<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SalesOrder;
use App\Models\StockDocument;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Phiếu bán hàng. Tiền là chuỗi thập phân đúng như lưu trong database. `total` = tiền khách phải trả (đã trừ `discount`), `balance` = khách còn nợ phiếu này;
 * `customer` là null với khách lẻ. `lines` và `payments` chỉ có khi đã nạp (xem chi tiết, vừa lập phiếu). Giá vốn (`cost_amount`) chỉ hiện với người được xem giá vốn.
 *
 * @mixin SalesOrder
 */
class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $costs = Gate::allows('seeCosts', StockDocument::class);
        $today = Carbon::now(config('inventory.timezone'))->toDateString();

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'sold_on' => $this->sold_on->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'is_overdue' => $this->isOverdue($today),
            'note' => $this->note,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : ['id' => $this->customer->uuid, 'code' => $this->customer->code, 'name' => $this->customer->name]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name]),
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->uuid, 'code' => $this->branch->code, 'name' => $this->branch->name]),
            'goods_total' => (string) $this->goods_total,
            'discount' => (string) $this->discount,
            'total' => (string) $this->total,
            'paid_amount' => (string) $this->paid_amount,
            'balance' => $this->balance(),
            'payment_status' => $this->paymentStatus(),
            'stock_document' => $this->whenLoaded('stockDocument', fn () => ['id' => $this->stockDocument->uuid, 'code' => $this->stockDocument->code]),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator === null ? null : ['id' => $this->creator->uuid, 'name' => $this->creator->name]),
            'created_at' => $this->created_at?->toIso8601String(),
            'line_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'product' => ['id' => $line->product->uuid, 'sku' => $line->product->sku, 'name' => $line->product->name, 'base_unit' => $line->product->baseUnit?->name],
                'batch' => $line->batch === null ? null : ['id' => $line->batch->uuid, 'batch_no' => $line->batch->batch_no, 'expires_at' => $line->batch->expires_at?->toDateString()],
                'unit' => $line->unit->name,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'line_total' => (string) $line->line_total,
                ...($costs ? ['cost_amount' => (string) $line->cost_amount] : []),
            ])->values()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->uuid,
                'amount' => (string) $payment->amount,
                'method' => $payment->method,
                'paid_on' => $payment->paid_on->toDateString(),
                'note' => $payment->note,
                'created_by' => $payment->creator === null ? null : ['id' => $payment->creator->uuid, 'name' => $payment->creator->name],
                'created_at' => $payment->created_at?->toIso8601String(),
                'voided' => $payment->voided_at === null ? null : [
                    'at' => $payment->voided_at->toIso8601String(),
                    'by' => $payment->voider === null ? null : ['id' => $payment->voider->uuid, 'name' => $payment->voider->name],
                    'reason' => $payment->void_reason,
                ],
            ])->values()),
        ];
    }
}
