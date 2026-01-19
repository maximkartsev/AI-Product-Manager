<?php

namespace App\Http\Controllers;

use App\Http\Resources\CreditTransaction as CreditTransactionResource;
use App\Models\CreditTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CreditTransactionController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = CreditTransaction::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['type', 'description', 'reference_type']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, CreditTransaction::class);

        $this->addFiltersCriteria($query, $filters, CreditTransaction::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => CreditTransactionResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('CreditTransactions retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, CreditTransaction::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = CreditTransaction::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new CreditTransactionResource($item), trans('CreditTransaction created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = CreditTransaction::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('CreditTransaction not found'));
        }

        return $this->sendResponse(new CreditTransactionResource($item), trans('CreditTransaction retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new CreditTransaction($input);

        return $this->sendResponse(new CreditTransactionResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = CreditTransaction::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('CreditTransaction not found'));
        }

        $input = $request->all();

        $rules = CreditTransaction::getRules($id);

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

        return $this->sendResponse(new CreditTransactionResource($item), trans('CreditTransaction updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = CreditTransaction::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('CreditTransaction not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('CreditTransaction deleted successfully'));
    }
}
