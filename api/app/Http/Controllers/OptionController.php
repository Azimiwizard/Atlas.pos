<?php

namespace App\Http\Controllers;

use App\Http\Requests\OptionStoreRequest;
use App\Http\Requests\OptionUpdateRequest;
use App\Http\Resources\OptionResource;
use App\Models\Option;
use App\Models\OptionGroup;
use Illuminate\Http\Request;

class OptionController extends Controller
{
    public function index(OptionGroup $optionGroup)
    {
        return OptionResource::collection($optionGroup->options()->paginate());
    }

    public function store(OptionStoreRequest $request, OptionGroup $optionGroup)
    {
        $option = $optionGroup->options()->create($request->validated());

        return new OptionResource($option);
    }

    public function show(OptionGroup $optionGroup, Option $option)
    {
        return new OptionResource($option);
    }

    public function update(OptionUpdateRequest $request, OptionGroup $optionGroup, Option $option)
    {
        $option->update($request->validated());

        return new OptionResource($option);
    }

    public function destroy(OptionGroup $optionGroup, Option $option)
    {
        $option->delete();

        return response()->noContent();
    }
}
