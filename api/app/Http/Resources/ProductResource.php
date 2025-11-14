<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'title' => $this->title,
            'price' => $this->price !== null ? (float) $this->price : null,
            'menu_category_id' => $this->menu_category_id,
            'category_id' => $this->menu_category_id,
            'category_name' => $this->menuCategory?->name
                ?? ($this->relationLoaded('category') ? $this->category?->name : null),
            'menu_category_name' => $this->menuCategory?->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'tax_code' => $this->tax_code,
            'image_url' => $this->image_url,
            'description' => $this->description,
            'sort_order' => $this->sort_order !== null ? (int) $this->sort_order : null,
            'track_stock' => (bool) ($this->track_stock ?? true),
            'is_active' => (bool) ($this->is_active ?? true),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
            'option_groups' => OptionGroupResource::collection($this->whenLoaded('optionGroups')),
            'stock_by_store' => $this->when(
                $this->relationLoaded('stockLevels'),
                fn () => $this->stockLevels
                    ->groupBy('store_id')
                    ->map(function ($levels, $storeId) {
                        $qty = $levels->sum(fn ($level) => (float) $level->qty_on_hand);
                        $store = $levels->first()?->store;

                        return [
                            'store_id' => $storeId,
                            'store_name' => $store?->name,
                            'store_code' => $store?->code,
                            'qty_on_hand' => round($qty, 3),
                        ];
                    })
                    ->values()
            ),
            'stock_summary' => $this->when(
                $this->relationLoaded('stockLevels'),
                fn () => $this->stockLevels
                    ->map(function ($level) {
                        return [
                            'tenant_id' => $level->tenant_id,
                            'store_id' => $level->store_id,
                            'store_name' => $level->store?->name,
                            'store_code' => $level->store?->code,
                            'variant_id' => $level->variant_id,
                            'variant_name' => $level->variant?->name,
                            'variant_sku' => $level->variant?->sku,
                            'qty_on_hand' => round((float) ($level->qty_on_hand ?? 0), 3),
                        ];
                    })
                    ->sortBy(fn ($entry) => [
                        $entry['variant_name'] ?? '',
                        $entry['store_name'] ?? '',
                    ])
                    ->values()
            ),
            'variants' => $this->when(
                $this->relationLoaded('variants'),
                fn () => $this->variants
                    ->map(fn ($variant) => [
                        'id' => $variant->id,
                        'name' => $variant->name,
                        'sku' => $variant->sku,
                        'barcode' => $variant->barcode,
                        'track_stock' => (bool) $variant->track_stock,
                        'is_default' => (bool) $variant->is_default,
                    ])
                    ->values()
            ),
        ];
    }
}
