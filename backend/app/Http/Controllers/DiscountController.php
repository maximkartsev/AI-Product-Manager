<?php

namespace App\Http\Controllers;

use App\Http\Resources\Discount as DiscountResource;
use App\Models\Discount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DiscountController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Discount::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'code', 'description', 'type']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Discount::class);

        $this->addFiltersCriteria($query, $filters, Discount::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => DiscountResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Discounts retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Discount::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Discount::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new DiscountResource($item), trans('Discount created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Discount::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Discount not found'));
        }

        return $this->sendResponse(new DiscountResource($item), trans('Discount retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Discount($input);

        return $this->sendResponse(new DiscountResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Discount::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Discount not found'));
        }

        $input = $request->all();

        $rules = Discount::getRules($id);

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

        return $this->sendResponse(new DiscountResource($item), trans('Discount updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Discount::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Discount not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Discount deleted successfully'));
    }
}
