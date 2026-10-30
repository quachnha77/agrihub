<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AuditLog;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'occurred_at' => Carbon::parse($this->occurred_at, 'UTC')->toIso8601ZuluString('microsecond'),
            'module' => $this->module,
            'action' => $this->action,
            'result' => $this->result,
            'reason' => $this->reason,
            'actor' => [
                'type' => $this->actor_type,
                'id' => $this->actor_uuid,
                'label' => $this->actor_label,
            ],
            'subject' => $this->subject_type === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => $this->subject_label,
            ],
            'branch' => $this->branch_label,
            'ip_address' => $this->ip_address,
            'device' => UserAgent::summary($this->user_agent),
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'before' => $this->before,
            'after' => $this->after,
            'metadata' => $this->metadata,
            // Chỉ có ở API của Platform Admin (đã nạp trong controller).
            'tenant' => $this->when(
                array_key_exists('tenant_info', $this->resource->getAttributes()),
                fn () => $this->resource->getAttribute('tenant_info'),
            ),
        ];
    }
}
