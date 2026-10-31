<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PurchaseReceipt;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phiếu nhập hàng. Tiền là chuỗi thập phân đúng như lưu trong database. `balance` = tiền hàng còn nợ nhà cung cấp (chi phí kèm theo không tính vào nợ);
 * `lines` và `payments` chỉ có khi đã nạp (xem chi tiết, vừa lập phiếu). Mỗi dòng có `cost` = giá vốn mỗi đơn vị đã nhập (đã gồm phần chi phí kèm theo phân bổ).
 *
 * @mixin PurchaseReceipt
 */
class PurchaseReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'supplier_ref' => $this->supplier_ref,
            'received_on' => $this->received_on->toDateString(),
            'note' => $this->note,
            'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->uuid, 'code' => $this->supplier->code, 'name' => $this->supplier->name]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name]),
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->uuid, 'code' => $this->branch->code, 'name' => $this->branch->name]),
            'goods_total' => (string) $this->goods_total,
            'extra_cost' => (string) $this->extra_cost,
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
                'unit_cost' => (string) $line->unit_cost,
                'line_total' => (string) $line->line_total,
                'extra_allocated' => (string) $line->extra_allocated,
                'cost' => Decimal::div(Decimal::add((string) $line->line_total, (string) $line->extra_allocated), (string) $line->quantity, 4),
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
