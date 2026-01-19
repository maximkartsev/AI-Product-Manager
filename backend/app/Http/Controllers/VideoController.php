<?php

namespace App\Http\Controllers;

use App\Http\Resources\Video as VideoResource;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VideoController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Video::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['title', 'description', 'status']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Video::class);

        $this->addFiltersCriteria($query, $filters, Video::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => VideoResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Videos retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Video::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Video::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new VideoResource($item), trans('Video created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Video::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Video not found'));
        }

        return $this->sendResponse(new VideoResource($item), trans('Video retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Video($input);

        return $this->sendResponse(new VideoResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Video::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Video not found'));
        }

        $input = $request->all();

        $rules = Video::getRules($id);

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

        return $this->sendResponse(new VideoResource($item), trans('Video updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Video::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Video not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Video deleted successfully'));
    }
}
