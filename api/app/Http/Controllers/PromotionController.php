<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $promotions = Promotion::query()
            ->with(['category:id,name', 'product:id,title'])
            ->where('tenant_id', $user->tenant_id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($promotions);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $this->validatePayload($request);
        $this->assertScopeOwnership($data, $user->tenant_id);

        $promotion = Promotion::create([
            'tenant_id' => $user->tenant_id,
            'name' => $data['name'],
            'type' => $data['type'],
            'value' => $data['value'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'applies_to' => $data['applies_to'],
            'category_id' => $data['category_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(
            $promotion->fresh(['category:id,name', 'product:id,title']),
            201
        );
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $user = $request->user();
        $this->ensureTenant($promotion, $user->tenant_id);

        $data = $this->validatePayload($request, true);
        $this->assertScopeOwnership($data, $user->tenant_id);

        $promotion->fill($data)->save();

        return response()->json(
            $promotion->fresh(['category:id,name', 'product:id,title'])
        );
    }

    protected function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['percent', 'amount'])],
            'value' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'applies_to' => [$isUpdate ? 'sometimes' : 'required', Rule::in(['all', 'category', 'product'])],
            'category_id' => ['nullable', 'string'],
            'product_id' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];

        $data = $request->validate($rules);

        $appliesTo = $data['applies_to'] ?? $request->input('applies_to');

        if (array_key_exists('category_id', $data) && empty($data['category_id'])) {
            $data['category_id'] = null;
        }

        if (array_key_exists('product_id', $data) && empty($data['product_id'])) {
            $data['product_id'] = null;
        }

        if ($appliesTo === 'category' && empty($data['category_id'])) {
            throw ValidationException::withMessages([
                'category_id' => ['Category is required for category promotions.'],
            ]);
        }

        if ($appliesTo === 'product' && empty($data['product_id'])) {
            throw ValidationException::withMessages([
                'product_id' => ['Product is required for product promotions.'],
            ]);
        }

        return $data;
    }

    protected function assertScopeOwnership(array $data, string $tenantId): void
    {
        if (!empty($data['category_id'])) {
            Category::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($data['category_id']);
        }

        if (!empty($data['product_id'])) {
            Product::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($data['product_id']);
        }
    }

    protected function ensureTenant(Promotion $promotion, string $tenantId): void
    {
        if ($promotion->tenant_id !== $tenantId) {
            abort(404);
        }
    }
}
