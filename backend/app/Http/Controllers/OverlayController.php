<?php

namespace App\Http\Controllers;

use App\Http\Resources\Overlay as OverlayResource;
use App\Models\Overlay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OverlayController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Overlay::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description', 'type', 'blend_mode']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Overlay::class);

        $this->addFiltersCriteria($query, $filters, Overlay::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => OverlayResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Overlays retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Overlay::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Overlay::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new OverlayResource($item), trans('Overlay created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Overlay::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Overlay not found'));
        }

        return $this->sendResponse(new OverlayResource($item), trans('Overlay retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Overlay($input);

        return $this->sendResponse(new OverlayResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Overlay::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Overlay not found'));
        }

        $input = $request->all();

        $rules = Overlay::getRules($id);

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

        return $this->sendResponse(new OverlayResource($item), trans('Overlay updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Overlay::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Overlay not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Overlay deleted successfully'));
    }
}
