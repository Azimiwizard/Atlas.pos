<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = (string) $this->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'role' => ['required', Rule::in(['admin', 'manager', 'cashier'])],
            'store_id' => ['nullable', 'string'],
        ];
    }
}
