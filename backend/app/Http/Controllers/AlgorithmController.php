<?php

namespace App\Http\Controllers;

use App\Http\Resources\Algorithm as AlgorithmResource;
use App\Models\Algorithm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AlgorithmController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Algorithm::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description', 'type', 'category']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Algorithm::class);

        $this->addFiltersCriteria($query, $filters, Algorithm::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => AlgorithmResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Algorithms retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Algorithm::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Algorithm::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new AlgorithmResource($item), trans('Algorithm created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Algorithm::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Algorithm not found'));
        }

        return $this->sendResponse(new AlgorithmResource($item), trans('Algorithm retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Algorithm($input);

        return $this->sendResponse(new AlgorithmResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Algorithm::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Algorithm not found'));
        }

        $input = $request->all();

        $rules = Algorithm::getRules($id);

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

        return $this->sendResponse(new AlgorithmResource($item), trans('Algorithm updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Algorithm::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Algorithm not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Algorithm deleted successfully'));
    }
}
