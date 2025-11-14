<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = (string) $this->user()->tenant_id;
        $userId = (string) $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($userId)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'role' => ['sometimes', 'required', Rule::in(['admin', 'manager', 'cashier'])],
            'store_id' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
