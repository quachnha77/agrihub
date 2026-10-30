<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Backup */
class BackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'scope' => $this->scope,
            'kind' => $this->kind,
            'status' => $this->status,
            'size_bytes' => $this->size_bytes,
            'checksum_sha256' => $this->checksum_sha256,
            'format_version' => $this->format_version,
            'schema_version' => $this->schema_version,
            'row_counts' => $this->row_counts === null ? null : (object) $this->row_counts,
            'original_name' => $this->original_name,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
