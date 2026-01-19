<?php

namespace App\Http\Controllers;

use App\Http\Resources\Filter as FilterResource;
use App\Models\Filter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FilterController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Filter::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description', 'type']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Filter::class);

        $this->addFiltersCriteria($query, $filters, Filter::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => FilterResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Filters retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Filter::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Filter::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new FilterResource($item), trans('Filter created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Filter::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Filter not found'));
        }

        return $this->sendResponse(new FilterResource($item), trans('Filter retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Filter($input);

        return $this->sendResponse(new FilterResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Filter::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Filter not found'));
        }

        $input = $request->all();

        $rules = Filter::getRules($id);

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

        return $this->sendResponse(new FilterResource($item), trans('Filter updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Filter::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Filter not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Filter deleted successfully'));
    }
}
