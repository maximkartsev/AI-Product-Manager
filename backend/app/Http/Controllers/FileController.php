<?php

namespace App\Http\Controllers;

use App\Http\Resources\File as FileResource;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FileController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = File::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'original_name', 'mime_type', 'type', 'status']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, File::class);

        $this->addFiltersCriteria($query, $filters, File::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => FileResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Files retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, File::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = File::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new FileResource($item), trans('File created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = File::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('File not found'));
        }

        return $this->sendResponse(new FileResource($item), trans('File retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new File($input);

        return $this->sendResponse(new FileResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = File::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('File not found'));
        }

        $input = $request->all();

        $rules = File::getRules($id);

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

        return $this->sendResponse(new FileResource($item), trans('File updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = File::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('File not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('File deleted successfully'));
    }
}
