<?php

namespace App\Http\Controllers;

use App\Http\Resources\Style as StyleResource;
use App\Models\Style;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StyleController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Style::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Style::class);

        $this->addFiltersCriteria($query, $filters, Style::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => StyleResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Styles retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Style::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Style::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new StyleResource($item), trans('Style created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Style::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Style not found'));
        }

        return $this->sendResponse(new StyleResource($item), trans('Style retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Style($input);

        return $this->sendResponse(new StyleResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Style::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Style not found'));
        }

        $input = $request->all();

        $rules = Style::getRules($id);

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

        return $this->sendResponse(new StyleResource($item), trans('Style updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Style::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Style not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Style deleted successfully'));
    }
}
