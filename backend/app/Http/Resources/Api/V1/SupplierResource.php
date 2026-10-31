<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Supplier */
class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'tax_code' => $this->tax_code,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'payment_terms_days' => $this->payment_terms_days,
            'notes' => $this->notes,
            'status' => $this->status,
            'contacts' => $this->whenLoaded('contacts', fn () => $this->contacts->map(fn ($c) => [
                'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'position' => $c->position,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
