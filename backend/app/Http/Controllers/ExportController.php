<?php

namespace App\Http\Controllers;

use App\Http\Resources\Export as ExportResource;
use App\Models\Export;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExportController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Export::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['format', 'resolution', 'quality', 'status', 'codec']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Export::class);

        $this->addFiltersCriteria($query, $filters, Export::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => ExportResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Exports retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Export::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Export::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new ExportResource($item), trans('Export created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Export::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Export not found'));
        }

        return $this->sendResponse(new ExportResource($item), trans('Export retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Export($input);

        return $this->sendResponse(new ExportResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Export::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Export not found'));
        }

        $input = $request->all();

        $rules = Export::getRules($id);

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

        return $this->sendResponse(new ExportResource($item), trans('Export updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Export::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Export not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Export deleted successfully'));
    }
}
