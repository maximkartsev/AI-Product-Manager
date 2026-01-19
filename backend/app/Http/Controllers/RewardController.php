<?php

namespace App\Http\Controllers;

use App\Http\Resources\Reward as RewardResource;
use App\Models\Reward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RewardController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Reward::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description', 'type', 'trigger_event']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Reward::class);

        $this->addFiltersCriteria($query, $filters, Reward::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => RewardResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Rewards retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Reward::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Reward::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new RewardResource($item), trans('Reward created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Reward::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Reward not found'));
        }

        return $this->sendResponse(new RewardResource($item), trans('Reward retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Reward($input);

        return $this->sendResponse(new RewardResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Reward::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Reward not found'));
        }

        $input = $request->all();

        $rules = Reward::getRules($id);

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

        return $this->sendResponse(new RewardResource($item), trans('Reward updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Reward::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Reward not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Reward deleted successfully'));
    }
}
