<?php

namespace App\Domain\Finance\Http\Requests;

use Illuminate\Validation\Validator;

class ExpenseUpdateRequest extends ExpenseRequest
{
    public function rules(): array
    {
        return [
            'store_id' => $this->sometimesRule($this->storeExistsRule()),
            'category' => ['sometimes', 'string', 'max:100'],
            'amount' => ['sometimes', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'incurred_at' => ['sometimes', 'date'],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator) {
            $fields = ['store_id', 'category', 'amount', 'incurred_at', 'vendor', 'notes'];
            $hasPayload = collect($fields)->contains(fn ($field) => $this->exists($field));

            if (!$hasPayload) {
                $validator->errors()->add('payload', 'Provide at least one field to update.');
            }
        });
    }

    /**
     * @param array<int, mixed> $rules
     * @return array<int, mixed>
     */
    protected function sometimesRule(array $rules): array
    {
        return array_merge(['sometimes'], $rules);
    }
}
