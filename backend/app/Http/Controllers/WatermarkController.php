<?php

namespace App\Http\Controllers;

use App\Http\Resources\Watermark as WatermarkResource;
use App\Models\Watermark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WatermarkController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Watermark::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'type', 'text_content', 'position']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Watermark::class);

        $this->addFiltersCriteria($query, $filters, Watermark::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => WatermarkResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Watermarks retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Watermark::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Watermark::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new WatermarkResource($item), trans('Watermark created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Watermark::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Watermark not found'));
        }

        return $this->sendResponse(new WatermarkResource($item), trans('Watermark retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Watermark($input);

        return $this->sendResponse(new WatermarkResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Watermark::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Watermark not found'));
        }

        $input = $request->all();

        $rules = Watermark::getRules($id);

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

        return $this->sendResponse(new WatermarkResource($item), trans('Watermark updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Watermark::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Watermark not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Watermark deleted successfully'));
    }
}
