<?php

namespace App\Domain\Finance\Http\Requests;

use App\Enums\UserRole;
use App\Services\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RuntimeException;

abstract class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && in_array($user->role, [UserRole::ADMIN, UserRole::MANAGER], true);
    }

    /**
     * @return array<int, mixed>
     */
    protected function storeExistsRule(): array
    {
        $tenantId = $this->tenantId();

        return [
            'nullable',
            'uuid',
            Rule::exists('stores', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
        ];
    }

    protected function tenantId(): string
    {
        $tenantId = $this->user()?->tenant_id ?? app(TenantManager::class)->id();

        if (!$tenantId) {
            throw new RuntimeException('Tenant context is required for finance expenses.');
        }

        return (string) $tenantId;
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $storeId = $this->input('store_id');
            $user = $this->user();

            if ($storeId && $user?->store_id && $user->store_id !== $storeId) {
                $validator->errors()->add('store_id', 'You are not allowed to manage expenses for this store.');
            }
        });
    }
}
