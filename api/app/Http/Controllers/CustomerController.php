<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\StoreManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(private StoreManager $stores)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'store_id' => ['nullable', 'string'],
        ]);

        $tenantId = (string) $request->user()->tenant_id;
        $perPage = (int) ($validated['per_page'] ?? 15);
        $search = $validated['search'] ?? null;
        $storeFilter = $validated['store_id'] ?? $this->stores->id() ?? $request->user()->store_id;

        if ($request->user()->role === 'cashier') {
            $storeFilter = $request->user()->store_id;
        }

        if ($storeFilter === 'all' && $request->user()->role !== 'cashier') {
            $storeFilter = null;
        }

        $query = Customer::query()
            ->where('tenant_id', $tenantId)
            ->withMax('orders as last_order_at', 'orders.created_at')
            ->orderBy('name');

        if ($storeFilter) {
            $query->where(function ($builder) use ($storeFilter) {
                $builder->whereNull('store_id')
                    ->orWhere('store_id', $storeFilter);
            });
        }

        if ($search) {
            $normalized = mb_strtolower($search);
            $like = "%{$normalized}%";

            $query->where(function ($builder) use ($like) {
                $builder
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$like]);
            });
        }

        $customers = $query->paginate($perPage)->appends($request->query());

        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('customers')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'notes' => ['nullable', 'string'],
            'store_id' => ['nullable', 'string'],
        ]);

        $storeId = $data['store_id'] ?? $this->stores->id() ?? $request->user()->store_id;
        if ($request->user()->role === 'cashier') {
            $storeId = $request->user()->store_id;
        }
        if ($storeId === 'all') {
            $storeId = null;
        }

        $customer = Customer::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'store_id' => $storeId,
        ]);

        return response()->json($customer, 201);
    }

    public function update(Request $request, string $customerId): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;

        $customer = Customer::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($customerId);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers')
                    ->ignore($customer->id)
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('customers')
                    ->ignore($customer->id)
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'notes' => ['nullable', 'string'],
            'store_id' => ['nullable', 'string'],
        ]);

        $customer->fill($data);
        $customer->save();

        return response()->json($customer);
    }

    public function show(Request $request, string $customerId): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;

        $customer = Customer::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($customerId);

        $storeId = $this->stores->id() ?? $request->user()->store_id;
        if ($request->user()->role === 'cashier') {
            $storeId = $request->user()->store_id;
        }
        if ($storeId === 'all') {
            $storeId = null;
        }

        if ($storeId && $customer->store_id && $customer->store_id !== $storeId) {
            abort(403, 'Customer belongs to a different store.');
        }

        $recentOrders = $customer->orders()
            ->select('orders.id', 'orders.total', 'orders.created_at', 'orders.status')
            ->orderByDesc('orders.created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'customer' => $customer,
            'recent_orders' => $recentOrders,
        ]);
    }
}
