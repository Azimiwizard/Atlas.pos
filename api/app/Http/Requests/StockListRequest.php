<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->user()?->role;

        return in_array($role, ['admin', 'manager'], true);
    }

    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'uuid'],
            'variant_id' => ['nullable', 'uuid'],
            'store_id' => ['nullable', 'uuid'],
        ];
    }
}

