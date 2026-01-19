<?php

namespace App\Http\Controllers;

use App\Http\Resources\Tier as TierResource;
use App\Models\Tier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TierController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Tier::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Tier::class);

        $this->addFiltersCriteria($query, $filters, Tier::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => TierResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Tiers retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Tier::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Tier::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new TierResource($item), trans('Tier created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Tier::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Tier not found'));
        }

        return $this->sendResponse(new TierResource($item), trans('Tier retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Tier($input);

        return $this->sendResponse(new TierResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Tier::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Tier not found'));
        }

        $input = $request->all();

        $rules = Tier::getRules($id);

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

        return $this->sendResponse(new TierResource($item), trans('Tier updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Tier::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Tier not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Tier deleted successfully'));
    }
}
