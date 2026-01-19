<?php

namespace App\Http\Controllers;

use App\Http\Resources\RecordTag as RecordTagResource;
use App\Models\RecordTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as Validator;

class RecordTagController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = RecordTag::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['tag_id', 'record_id']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, RecordTag::class);

        $this->addFiltersCriteria($query, $filters, RecordTag::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $items->load(['tag', 'record']);

        $response = [
            'items' => RecordTagResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('RecordTags retrieved successfully'));
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

        $validator = Validator::make($input, RecordTag::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = RecordTag::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        $item->load(['tag', 'record']);

        return $this->sendResponse(new RecordTagResource($item), trans('RecordTag created successfully'));
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $item = RecordTag::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('RecordTag not found'));
        }

        $item->load(['tag', 'record']);

        return $this->sendResponse(new RecordTagResource($item), trans('RecordTag retrieved successfully'));

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

        $item = new RecordTag($input);

        $item->load(['tag', 'record']);

        return $this->sendResponse(new RecordTagResource($item), null);
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
        $item = RecordTag::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('RecordTag not found'));
        }

        $input = $request->all();

        $rules = RecordTag::getRules($id);

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

        $item->load(['tag', 'record']);

        return $this->sendResponse(new RecordTagResource($item), trans('RecordTag updated successfully'));

    }


    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $item = RecordTag::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('RecordTag not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('RecordTag deleted successfully'));

    }
}


