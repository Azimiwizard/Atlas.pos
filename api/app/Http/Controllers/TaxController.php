<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        $query = Tax::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('name');

        if (empty($validated['include_inactive'])) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'inclusive' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tax = Tax::create([
            'tenant_id' => $user->tenant_id,
            'name' => $data['name'],
            'rate' => $data['rate'],
            'inclusive' => $data['inclusive'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($tax, 201);
    }

    public function update(Request $request, Tax $tax): JsonResponse
    {
        $user = $request->user();
        $this->ensureTenant($tax, $user->tenant_id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'inclusive' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $tax->fill($data)->save();

        return response()->json($tax);
    }

    protected function ensureTenant(Tax $tax, string $tenantId): void
    {
        if ($tax->tenant_id !== $tenantId) {
            abort(404);
        }
    }
}
