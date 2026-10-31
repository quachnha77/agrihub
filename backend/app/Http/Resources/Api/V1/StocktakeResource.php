<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Stocktake;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một đợt kiểm kê. `summary` (số dòng, đã đếm, lệch) chỉ có khi đã nạp bằng withCount (danh sách, chi tiết). `difference_value` (giá trị chênh lệch)
 * do controller gắn vào chỉ khi người xem được phép xem giá vốn (StockDocumentPolicy::seeCosts).
 *
 * @mixin Stocktake
 */
class StocktakeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = isset($this->lines_count);

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'status' => $this->status,
            'note' => $this->note,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name]),
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->uuid, 'code' => $this->branch->code, 'name' => $this->branch->name]),
            'started_by' => $this->whenLoaded('starter', fn () => $this->starter === null ? null : ['id' => $this->starter->uuid, 'name' => $this->starter->name]),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_by' => $this->whenLoaded('finisher', fn () => $this->finisher === null ? null : ['id' => $this->finisher->uuid, 'name' => $this->finisher->name]),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'adjustment_document' => $this->whenLoaded('adjustmentDocument', fn () => $this->adjustmentDocument === null ? null : ['id' => $this->adjustmentDocument->uuid, 'code' => $this->adjustmentDocument->code]),
            $this->mergeWhen($summary, fn () => [
                'summary' => ['lines' => (int) $this->lines_count, 'counted' => (int) $this->counted_count, 'differing' => (int) $this->differing_count],
            ]),
            $this->mergeWhen($this->getAttribute('difference_value') !== null, fn () => ['difference_value' => $this->getAttribute('difference_value')]),
        ];
    }
}
