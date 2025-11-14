<?php

namespace App\Http\Controllers;

use App\Models\Register;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegisterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'include_inactive' => ['sometimes', 'in:true,1'],
            'store_id' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $query = Register::query()
            ->where('tenant_id', $user->tenant_id)
            ->with('store')
            ->orderBy('name');

        $includeInactive = array_key_exists('include_inactive', $validated);
        if (!$includeInactive) {
            $query->where('is_active', true);
        }

        $storeFilter = $request->query('store_id') ?? $request->attributes->get('store_id');

        if ($user->role === 'cashier') {
            $storeFilter = $user->store_id;
        }

        if ($storeFilter === 'all' && $user->role !== 'cashier') {
            $storeFilter = null;
        }

        if ($storeFilter) {
            $query->where('store_id', $storeFilter);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeRoles($user->role, ['admin', 'manager']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'store_id' => ['nullable', 'string'],
        ]);

        $storeId = $data['store_id'] ?? $request->attributes->get('store_id');
        if ($storeId === 'all') {
            $storeId = null;
        }

        if (!$storeId) {
            abort(422, 'Register must be assigned to a store.');
        }

        $store = Store::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->findOrFail($storeId);

        $register = Register::create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ])->load('store');

        return response()->json($register, 201);
    }

    public function update(Request $request, Register $register): JsonResponse
    {
        $user = $request->user();
        $this->authorizeRoles($user->role, ['admin', 'manager']);

        abort_unless($register->tenant_id === $user->tenant_id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'store_id' => ['nullable', 'string'],
        ]);

        if (array_key_exists('store_id', $data)) {
            $storeId = $data['store_id'];
            if ($storeId === 'all') {
                $storeId = null;
            }

            if ($storeId) {
                $store = Store::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('is_active', true)
                    ->findOrFail($storeId);
                $register->store_id = $store->id;
            } else {
                $register->store_id = null;
            }
            unset($data['store_id']);
        }

        $register->fill($data);
        $register->save();
        $register->load('store');

        return response()->json($register);
    }

    protected function authorizeRoles(string $role, array $allowed): void
    {
        if (!in_array($role, $allowed, true)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }
}
