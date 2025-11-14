<?php

namespace App\Http\Controllers;

use App\Http\Requests\OptionGroupStoreRequest;
use App\Http\Requests\OptionGroupUpdateRequest;
use App\Http\Resources\OptionGroupResource;
use App\Models\Product;
use App\Models\OptionGroup;
use Illuminate\Http\Request;

class OptionGroupController extends Controller
{
    public function index(Product $product)
    {
        return OptionGroupResource::collection($product->optionGroups()->paginate());
    }

    public function store(OptionGroupStoreRequest $request, Product $product)
    {
        $optionGroup = $product->optionGroups()->create($request->validated());

        return new OptionGroupResource($optionGroup);
    }

    public function show(Product $product, OptionGroup $optionGroup)
    {
        return new OptionGroupResource($optionGroup);
    }

    public function update(OptionGroupUpdateRequest $request, Product $product, OptionGroup $optionGroup)
    {
        $optionGroup->update($request->validated());

        return new OptionGroupResource($optionGroup);
    }

    public function destroy(Product $product, OptionGroup $optionGroup)
    {
        $optionGroup->delete();

        return response()->noContent();
    }
}
