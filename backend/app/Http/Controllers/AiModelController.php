<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiModel as AiModelResource;
use App\Models\AiModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AiModelController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = AiModel::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description', 'provider', 'type']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, AiModel::class);

        $this->addFiltersCriteria($query, $filters, AiModel::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => AiModelResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('AiModels retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, AiModel::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = AiModel::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new AiModelResource($item), trans('AiModel created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = AiModel::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('AiModel not found'));
        }

        return $this->sendResponse(new AiModelResource($item), trans('AiModel retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new AiModel($input);

        return $this->sendResponse(new AiModelResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = AiModel::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('AiModel not found'));
        }

        $input = $request->all();

        $rules = AiModel::getRules($id);

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

        return $this->sendResponse(new AiModelResource($item), trans('AiModel updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = AiModel::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('AiModel not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('AiModel deleted successfully'));
    }
}
