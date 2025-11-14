<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Http\Requests\ExpenseStoreRequest;
use App\Domain\Finance\Http\Requests\ExpenseUpdateRequest;
use App\Domain\Finance\Http\Resources\ExpenseResource;
use App\Domain\Finance\Models\Expense;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class ExpenseController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Expense::class, 'expense');
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'store_id' => [
                'nullable',
                'uuid',
                Rule::exists('stores', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'category' => ['nullable', 'string', 'max:100'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $perPage = max(1, min($perPage, 100));
        [$sortField, $sortDirection] = $this->resolveSort($validated['sort'] ?? null);

        $query = Expense::query()->with($this->relations());
        $this->applyStoreScope($query, $user);

        if (!empty($validated['store_id'])) {
            $this->assertStoreAccess($user, $validated['store_id']);
            $query->where('store_id', $validated['store_id']);
        }

        $likeOperator = $this->likeOperator();

        if (!empty($validated['category'])) {
            $query->where('category', $likeOperator, $this->wildcard($validated['category']));
        }

        if (!empty($validated['vendor'])) {
            $query->where('vendor', $likeOperator, $this->wildcard($validated['vendor']));
        }

        if (!empty($validated['date_from'])) {
            $query->where('incurred_at', '>=', CarbonImmutable::parse($validated['date_from'])->startOfDay());
        }

        if (!empty($validated['date_to'])) {
            $query->where('incurred_at', '<=', CarbonImmutable::parse($validated['date_to'])->endOfDay());
        }

        $paginator = $query
            ->orderBy($sortField, $sortDirection)
            ->paginate($perPage)
            ->appends($request->query());

        return ExpenseResource::collection($paginator);
    }

    public function store(ExpenseStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        $expense = Expense::create($data)->load($this->relations());

        return (new ExpenseResource($expense))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Expense $expense): ExpenseResource
    {
        return new ExpenseResource($expense->loadMissing($this->relations()));
    }

    public function update(ExpenseUpdateRequest $request, Expense $expense): ExpenseResource
    {
        $payload = $request->validated();

        if (array_key_exists('store_id', $payload)) {
            $this->assertStoreAccess($request->user(), $payload['store_id']);
        }

        $expense->fill($payload)->save();

        return new ExpenseResource($expense->load($this->relations()));
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json([], 204);
    }

    protected function relations(): array
    {
        return [
            'store:id,name,code',
            'creator:id,name,email',
        ];
    }

    protected function resolveSort(?string $sort): array
    {
        $allowed = ['incurred_at', 'amount', 'category'];
        $direction = 'desc';
        $field = 'incurred_at';

        if ($sort) {
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $candidate = ltrim($sort, '-');

            if (in_array($candidate, $allowed, true)) {
                $field = $candidate;
            }
        }

        return [$field, $direction];
    }

    protected function wildcard(string $value): string
    {
        return '%'.$value.'%';
    }

    protected function likeOperator(): string
    {
        return Expense::query()->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    protected function applyStoreScope(Builder $query, ?User $user): void
    {
        if ($user) {
            $query->forStoreAccess($user);
        }
    }

    protected function assertStoreAccess(?User $user, ?string $storeId): void
    {
        if (!$storeId || !$user?->store_id) {
            return;
        }

        abort_unless($user->store_id === $storeId, 403, 'You are not allowed to manage expenses for this store.');
    }

    protected function tenantId(Request $request): string
    {
        $tenantId = $request->user()?->tenant_id ?? app(TenantManager::class)->id();

        if (!$tenantId) {
            throw new RuntimeException('Tenant context missing for expense query.');
        }

        return (string) $tenantId;
    }
}
