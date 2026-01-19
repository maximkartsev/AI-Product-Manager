<?php

namespace App\Http\Controllers;

use App\Http\Resources\Purchase as PurchaseResource;
use App\Models\Purchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PurchaseController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Purchase::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['type', 'status', 'currency', 'invoice_number']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Purchase::class);

        $this->addFiltersCriteria($query, $filters, Purchase::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => PurchaseResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Purchases retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Purchase::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Purchase::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new PurchaseResource($item), trans('Purchase created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Purchase::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Purchase not found'));
        }

        return $this->sendResponse(new PurchaseResource($item), trans('Purchase retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Purchase($input);

        return $this->sendResponse(new PurchaseResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Purchase::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Purchase not found'));
        }

        $input = $request->all();

        $rules = Purchase::getRules($id);

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

        return $this->sendResponse(new PurchaseResource($item), trans('Purchase updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Purchase::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Purchase not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Purchase deleted successfully'));
    }
}
