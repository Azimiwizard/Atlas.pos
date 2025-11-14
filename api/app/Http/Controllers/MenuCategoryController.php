<?php

namespace App\Http\Controllers;

use App\Http\Requests\MenuCategoryStoreRequest;
use App\Http\Requests\MenuCategoryUpdateRequest;
use App\Http\Resources\MenuCategoryResource;
use App\Models\MenuCategory;
use Illuminate\Http\Request;

class MenuCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = MenuCategory::query();

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->has('sortBy')) {
            $query->orderBy($request->input('sortBy'), $request->input('sortOrder', 'asc'));
        }

        return MenuCategoryResource::collection($query->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(MenuCategoryStoreRequest $request)
    {
        $menuCategory = MenuCategory::create($request->validated());

        return new MenuCategoryResource($menuCategory);
    }

    /**
     * Display the specified resource.
     */
    public function show(MenuCategory $menuCategory)
    {
        return new MenuCategoryResource($menuCategory);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(MenuCategoryUpdateRequest $request, MenuCategory $menuCategory)
    {
        $menuCategory->update($request->validated());

        return new MenuCategoryResource($menuCategory);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MenuCategory $menuCategory)
    {
        $menuCategory->delete();

        return response()->noContent();
    }
}
