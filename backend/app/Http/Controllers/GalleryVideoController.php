<?php

namespace App\Http\Controllers;

use App\Http\Resources\GalleryVideo as GalleryVideoResource;
use App\Models\GalleryVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GalleryVideoController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = GalleryVideo::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['title', 'description']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, GalleryVideo::class);

        $this->addFiltersCriteria($query, $filters, GalleryVideo::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => GalleryVideoResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('GalleryVideos retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, GalleryVideo::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = GalleryVideo::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new GalleryVideoResource($item), trans('GalleryVideo created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = GalleryVideo::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('GalleryVideo not found'));
        }

        return $this->sendResponse(new GalleryVideoResource($item), trans('GalleryVideo retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new GalleryVideo($input);

        return $this->sendResponse(new GalleryVideoResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = GalleryVideo::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('GalleryVideo not found'));
        }

        $input = $request->all();

        $rules = GalleryVideo::getRules($id);

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

        return $this->sendResponse(new GalleryVideoResource($item), trans('GalleryVideo updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = GalleryVideo::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('GalleryVideo not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('GalleryVideo deleted successfully'));
    }
}
