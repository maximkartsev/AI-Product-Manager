<?php

namespace App\Http\Controllers;

use App\Http\Resources\Rollout as RolloutResource;
use App\Models\Rollout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as Validator;

class RolloutController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Rollout::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['user_id', 'commit_id', 'date']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Rollout::class);

        $this->addFiltersCriteria($query, $filters, Rollout::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $items->load(['user']);

        $response = [
            'items' => RolloutResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Rollouts retrieved successfully'));
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Rollout::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Rollout::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        $item->load(['user']);

        return $this->sendResponse(new RolloutResource($item), trans('Rollout created successfully'));
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $item = Rollout::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Rollout not found'));
        }

        $item->load(['user']);

        return $this->sendResponse(new RolloutResource($item), trans('Rollout retrieved successfully'));

    }

    /**
     * Show the form for creating a new resource
     *
     * @param \Illuminate\Http\Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Rollout($input);

        $item->load(['user']);

        return $this->sendResponse(new RolloutResource($item), null);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $item = Rollout::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Rollout not found'));
        }

        $input = $request->all();

        $rules = Rollout::getRules($id);

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

        $item->load(['user']);

        return $this->sendResponse(new RolloutResource($item), trans('Rollout updated successfully'));

    }


    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $item = Rollout::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Rollout not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Rollout deleted successfully'));

    }
}


