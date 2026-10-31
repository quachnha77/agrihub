<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hạn mức công nợ trả về dạng chuỗi thập phân ("5000000.00") như lưu trong database, không qua số thực.
 *
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    private const TYPE_LABELS = ['individual' => 'Cá nhân / nông hộ', 'agent' => 'Đại lý', 'cooperative' => 'Hợp tác xã', 'company' => 'Doanh nghiệp'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'type' => $this->type,
            'type_label' => self::TYPE_LABELS[$this->type] ?? $this->type,
            'name' => $this->name,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->uuid, 'code' => $this->branch->code, 'name' => $this->branch->name]),
            'group' => $this->whenLoaded('group', fn () => $this->group === null ? null : ['id' => $this->group->uuid, 'name' => $this->group->name]),
            'phone' => $this->phone,
            'email' => $this->email,
            'tax_code' => $this->tax_code,
            'credit_limit' => (string) $this->credit_limit,
            'credit_days' => $this->credit_days,
            'notes' => $this->notes,
            'status' => $this->status,
            'addresses' => $this->whenLoaded('addresses', fn () => $this->addresses->map(fn ($a) => [
                'label' => $a->label, 'address' => $a->address, 'ward' => $a->ward, 'district' => $a->district, 'province' => $a->province, 'is_default' => $a->is_default,
            ])->values()),
            'contacts' => $this->whenLoaded('contacts', fn () => $this->contacts->map(fn ($c) => [
                'name' => $c->name, 'phone' => $c->phone, 'position' => $c->position, 'note' => $c->note,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
