<?php

namespace App\Http\Controllers;

use App\Http\Resources\Subscription as SubscriptionResource;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubscriptionController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Subscription::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['status', 'billing_cycle', 'payment_method', 'external_id']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Subscription::class);

        $this->addFiltersCriteria($query, $filters, Subscription::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => SubscriptionResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Subscriptions retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Subscription::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Subscription::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new SubscriptionResource($item), trans('Subscription created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Subscription::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Subscription not found'));
        }

        return $this->sendResponse(new SubscriptionResource($item), trans('Subscription retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Subscription($input);

        return $this->sendResponse(new SubscriptionResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Subscription::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Subscription not found'));
        }

        $input = $request->all();

        $rules = Subscription::getRules($id);

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

        return $this->sendResponse(new SubscriptionResource($item), trans('Subscription updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Subscription::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Subscription not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Subscription deleted successfully'));
    }
}
