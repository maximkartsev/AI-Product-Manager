<?php

namespace App\Http\Controllers;

use App\Http\Resources\Tag as TagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as Validator;

class TagController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Tag::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Tag::class);

        $this->addFiltersCriteria($query, $filters, Tag::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => TagResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Tags retrieved successfully'));
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

        $validator = Validator::make($input, Tag::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Tag::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new TagResource($item), trans('Tag created successfully'));
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $item = Tag::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Tag not found'));
        }

        return $this->sendResponse(new TagResource($item), trans('Tag retrieved successfully'));

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

        $item = new Tag($input);

        return $this->sendResponse(new TagResource($item), null);
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
        $item = Tag::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Tag not found'));
        }

        $input = $request->all();

        $rules = Tag::getRules($id);

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

        return $this->sendResponse(new TagResource($item), trans('Tag updated successfully'));

    }


    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $item = Tag::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Tag not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Tag deleted successfully'));

    }
}


