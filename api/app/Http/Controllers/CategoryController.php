<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $query = Category::query()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('name');

        if (isset($validated['is_active'])) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        if (!empty($validated['search'])) {
            $term = mb_strtolower($validated['search']);
            $query->where(function ($builder) use ($term) {
                $operator = $this->likeOperator();
                $builder
                    ->whereRaw('LOWER(name) ' . $operator . ' ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(slug) ' . $operator . ' ?', ["%{$term}%"]);
            });
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $slug = $this->generateUniqueSlug(
            $user->tenant_id,
            $data['slug'] ?? Str::slug($data['name'])
        );

        $category = Category::create([
            'tenant_id' => $user->tenant_id,
            'name' => $data['name'],
            'slug' => $slug,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($category, 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $user = $request->user();
        $this->ensureTenant($category, $user->tenant_id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $category->name = $data['name'];
        }

        if (array_key_exists('is_active', $data)) {
            $category->is_active = (bool) $data['is_active'];
        }

        if (array_key_exists('slug', $data)) {
            $slugSource = $data['slug'] ?? Str::slug($category->name);
            $category->slug = $this->generateUniqueSlug(
                $user->tenant_id,
                $slugSource,
                $category->id
            );
        }

        $category->save();

        return response()->json($category);
    }

    protected function likeOperator(): string
    {
        $driver = Category::query()->getConnection()->getDriverName();

        return $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    protected function ensureTenant(Category $category, string $tenantId): void
    {
        if ($category->tenant_id !== $tenantId) {
            abort(404);
        }
    }

    protected function generateUniqueSlug(string $tenantId, string $baseSlug, ?string $ignoreId = null): string
    {
        $slug = Str::slug($baseSlug) ?: Str::random(8);
        $original = $slug;
        $index = 1;

        while (
            Category::query()
                ->where('tenant_id', $tenantId)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$original}-{$index}";
            $index++;
        }

        return $slug;
    }
}
