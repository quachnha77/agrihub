<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StockDocument;
use App\Models\StockTransfer;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Facades\Gate;

/**
 * Phiếu chuyển kho: kho gửi → kho nhận, người làm, thời điểm, hai phiếu kho đi kèm và (khi đã nạp) các dòng hàng. Số lượng là SỐ DƯƠNG theo đơn vị cơ sở kèm đơn vị
 * người dùng đã nhập. Giá vốn (`unit_cost`, `value`) chỉ có khi người xem được phép (StockDocumentPolicy::seeCosts).
 *
 * @mixin StockTransfer
 */
class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $costs = Gate::allows('seeCosts', StockDocument::class);
        $out = $this->relationLoaded('outDocument') ? $this->outDocument : null;
        $movements = $out?->relationLoaded('movements') ? $out->movements : null;

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'note' => $this->note,
            'from' => $this->side('fromWarehouse', 'fromBranch'),
            'to' => $this->side('toWarehouse', 'toBranch'),
            'performed_by' => $this->whenLoaded('performer', fn () => $this->performer === null ? null : ['id' => $this->performer->uuid, 'name' => $this->performer->name]),
            'performed_at' => $this->performed_at?->toIso8601String(),
            'line_count' => $this->whenCounted('outMovements'),
            'documents' => $this->when($out !== null, fn () => [
                'out' => ['id' => $out->uuid, 'code' => $out->code],
                'in' => $this->relationLoaded('inDocument') && $this->inDocument !== null ? ['id' => $this->inDocument->uuid, 'code' => $this->inDocument->code] : null,
            ]),
            'lines' => $this->when($movements !== null, fn () => $movements->map(fn ($m) => [
                'product' => ['id' => $m->product->uuid, 'sku' => $m->product->sku, 'name' => $m->product->name, 'base_unit' => $m->product->baseUnit->name],
                'batch' => $m->batch === null ? null : ['id' => $m->batch->uuid, 'batch_no' => $m->batch->batch_no, 'expires_at' => $m->batch->expires_at?->toDateString()],
                'quantity' => Decimal::abs((string) $m->quantity_delta),
                'entered' => ['unit' => $m->enteredUnit->name, 'quantity' => (string) $m->entered_quantity, 'factor' => rtrim(rtrim((string) $m->entered_factor, '0'), '.')],
                ...($costs ? ['unit_cost' => (string) $m->unit_cost, 'value' => Decimal::money(Decimal::abs(Decimal::mul((string) $m->quantity_delta, (string) $m->unit_cost, 2)))] : []),
            ])->values()),
            $this->mergeWhen($costs && $movements !== null, fn () => [
                'value' => Decimal::money($movements->reduce(fn (string $sum, $m) => Decimal::add($sum, Decimal::abs(Decimal::mul((string) $m->quantity_delta, (string) $m->unit_cost, 2))), '0')),
            ]),
        ];
    }

    /** @return array{warehouse: array<string, string>, branch: array<string, string>}|MissingValue */
    private function side(string $warehouse, string $branch): mixed
    {
        return $this->when($this->relationLoaded($warehouse) && $this->relationLoaded($branch), fn () => [
            'warehouse' => ['id' => $this->{$warehouse}->uuid, 'code' => $this->{$warehouse}->code, 'name' => $this->{$warehouse}->name],
            'branch' => ['id' => $this->{$branch}->uuid, 'code' => $this->{$branch}->code, 'name' => $this->{$branch}->name],
        ]);
    }
}
