<?php

namespace App\Http\Controllers;

use App\Http\Resources\Payment as PaymentResource;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['provider', 'method', 'status', 'external_id', 'card_brand']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Payment::class);

        $this->addFiltersCriteria($query, $filters, Payment::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => PaymentResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Payments retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Payment::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Payment::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new PaymentResource($item), trans('Payment created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Payment::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Payment not found'));
        }

        return $this->sendResponse(new PaymentResource($item), trans('Payment retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Payment($input);

        return $this->sendResponse(new PaymentResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Payment::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Payment not found'));
        }

        $input = $request->all();

        $rules = Payment::getRules($id);

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

        return $this->sendResponse(new PaymentResource($item), trans('Payment updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Payment::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Payment not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Payment deleted successfully'));
    }
}
