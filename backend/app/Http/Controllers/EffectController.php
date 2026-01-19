<?php

namespace App\Http\Controllers;

use App\Http\Resources\Effect as EffectResource;
use App\Models\Effect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EffectController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Effect::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description', 'type']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Effect::class);

        $this->addFiltersCriteria($query, $filters, Effect::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => EffectResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Effects retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Effect::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Effect::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new EffectResource($item), trans('Effect created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Effect::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Effect not found'));
        }

        return $this->sendResponse(new EffectResource($item), trans('Effect retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Effect($input);

        return $this->sendResponse(new EffectResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Effect::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Effect not found'));
        }

        $input = $request->all();

        $rules = Effect::getRules($id);

        foreach ($rules as $k => $v) {
            if (!array_key_exists($k, $input)) {
                unset($rules[$k]);
            }
        }

        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        $item->fill($input);

        try {
            $item->save();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        $item->fresh();

        return $this->sendResponse(new EffectResource($item), trans('Effect updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Effect::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Effect not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Effect deleted successfully'));
    }
}
