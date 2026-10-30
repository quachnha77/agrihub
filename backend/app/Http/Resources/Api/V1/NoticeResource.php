<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Notice */
class NoticeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'level' => $this->level,
            'title' => $this->title,
            'body' => $this->body,
            // Không đặt tên "data": trùng khóa bọc của Laravel nên phản hồi một thông báo sẽ không được bọc trong { "data": ... }.
            'payload' => $this->data === null ? null : (object) $this->data,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
