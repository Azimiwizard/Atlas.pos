<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OptionGroupResource extends JsonResource
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
            'name' => $this->name,
            'selection_type' => $this->selection_type,
            'min' => $this->min,
            'max' => $this->max,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'options' => OptionResource::collection($this->whenLoaded('options')),
        ];
    }
}