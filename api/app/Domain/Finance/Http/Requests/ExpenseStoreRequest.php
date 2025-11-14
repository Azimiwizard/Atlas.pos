<?php

namespace App\Domain\Finance\Http\Requests;

class ExpenseStoreRequest extends ExpenseRequest
{
    public function rules(): array
    {
        return [
            'store_id' => $this->storeExistsRule(),
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'incurred_at' => ['required', 'date'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
