<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLevelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'product_id' => $this->variant?->product_id,
            'store_id' => $this->store?->id ?? $this->store_id,
            'store_name' => $this->store?->name,
            'store_code' => $this->store?->code,
            'variant_id' => $this->variant?->id ?? $this->variant_id,
            'variant_name' => $this->variant?->name,
            'variant_sku' => $this->variant?->sku,
            'qty_on_hand' => round((float) ($this->qty_on_hand ?? 0), 3),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
