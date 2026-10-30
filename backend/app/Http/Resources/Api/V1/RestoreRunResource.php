<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RestoreRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RestoreRun */
class RestoreRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'backup_id' => $this->whenLoaded('backup', fn () => $this->backup->uuid),
            'pre_restore_backup_id' => $this->whenLoaded('preBackup', fn () => $this->preBackup?->uuid),
            'row_counts' => $this->row_counts === null ? null : (object) $this->row_counts,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
