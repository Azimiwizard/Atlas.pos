<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\MenuCategoryStoreRequest;
use App\Http\Requests\MenuCategoryUpdateRequest;
use App\Http\Resources\MenuCategoryResource;
use App\Models\MenuCategory;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MenuCategoryController extends Controller
{
    public function __construct(private TenantManager $tenantManager)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'is_active' => ['nullable', 'in:true,false,1,0'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', 'max:64'],
        ]);

        [$sortField, $sortDirection] = $this->parseSort($validated['sort'] ?? null);

        $tenantId = $this->tenantManager->id();
        if (!$tenantId) {
            abort(403, 'Tenant context missing.');
        }

        $query = MenuCategory::query()->where('tenant_id', $tenantId);

        if (!empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where('name', 'like', "%{$term}%");
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', in_array((string) $validated['is_active'], ['true', '1'], true));
        }

        $query->orderBy($sortField, $sortDirection)->orderBy('name');

        $perPage = (int) ($validated['per_page'] ?? 50);

        if ($perPage > 0) {
            $paginator = $query->paginate($perPage)->appends($request->query());

            return MenuCategoryResource::collection($paginator)->response();
        }

        $categories = $query->get();

        return MenuCategoryResource::collection($categories)->response();
    }

    public function store(MenuCategoryStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $tenantId = $this->tenantManager->id();
        if (!$tenantId) {
            throw ValidationException::withMessages([
                'tenant' => ['Tenant context could not be resolved.'],
            ]);
        }

        $category = MenuCategory::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'image_url' => $data['image_url'] ?? null,
        ]);

        return (new MenuCategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(MenuCategory $menuCategory): JsonResponse
    {
        $this->ensureCategoryTenant($menuCategory);

        return (new MenuCategoryResource($menuCategory))->response();
    }

    public function update(MenuCategoryUpdateRequest $request, MenuCategory $menuCategory): JsonResponse
    {
        $this->ensureCategoryTenant($menuCategory);

        $data = $request->validated();

        $menuCategory->fill([
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? $menuCategory->sort_order,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $menuCategory->is_active,
            'image_url' => $data['image_url'] ?? $menuCategory->image_url,
        ])->save();

        return (new MenuCategoryResource($menuCategory->refresh()))->response();
    }

    public function destroy(MenuCategory $menuCategory): JsonResponse
    {
        $this->ensureCategoryTenant($menuCategory);
        $menuCategory->delete();

        return response()->json([], 204);
    }

    public function toggle(MenuCategory $menuCategory): JsonResponse
    {
        $this->ensureCategoryTenant($menuCategory);

        $menuCategory->is_active = !$menuCategory->is_active;
        $menuCategory->save();

        return (new MenuCategoryResource($menuCategory->refresh()))->response();
    }

    protected function parseSort(?string $sort): array
    {
        $default = ['sort_order', 'asc'];

        if (!$sort) {
            return $default;
        }

        $parts = array_map('trim', explode(':', $sort));
        $field = $parts[0] ?? $default[0];
        $direction = strtolower($parts[1] ?? $default[1]);

        $allowedFields = ['sort_order', 'name', 'created_at'];
        $allowedDirections = ['asc', 'desc'];

        if (!in_array($field, $allowedFields, true)) {
            $field = $default[0];
        }

        if (!in_array($direction, $allowedDirections, true)) {
            $direction = $default[1];
        }

        return [$field, $direction];
    }

    protected function ensureCategoryTenant(MenuCategory $category): void
    {
        $tenantId = $this->tenantManager->id();

        if (!$tenantId || $category->tenant_id !== $tenantId) {
            abort(404);
        }
    }
}
