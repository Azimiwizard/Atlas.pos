<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OptionGroupStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'selection_type' => ['required', Rule::in(['single', 'multiple'])],
            'min' => ['nullable', 'integer', 'min:0'],
            'max' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $selectionType = $this->input('selection_type');
            $min = $this->input('min');
            $max = $this->input('max');

            if ($selectionType === 'single' && $max !== null && (int) $max > 1) {
                $validator->errors()->add('max', 'Single-select groups cannot have a max greater than 1.');
            }

            if ($min !== null && $max !== null && (int) $min > (int) $max) {
                $validator->errors()->add('min', 'The minimum required options cannot exceed the maximum.');
            }
        });
    }
}
