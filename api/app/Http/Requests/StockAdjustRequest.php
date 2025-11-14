<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->user()?->role;

        return in_array($role, ['admin', 'manager'], true);
    }

    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'uuid', 'required_without:variant_id'],
            'variant_id' => ['nullable', 'uuid', 'required_without:product_id'],
            'store_id' => ['required', 'uuid'],
            'qty_delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'in:manual_adjustment,initial_stock,correction,wastage'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
