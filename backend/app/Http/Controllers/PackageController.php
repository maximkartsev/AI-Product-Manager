<?php

namespace App\Http\Controllers;

use App\Http\Resources\Package as PackageResource;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PackageController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Package::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description', 'type']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Package::class);

        $this->addFiltersCriteria($query, $filters, Package::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => PackageResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Packages retrieved successfully'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Package::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Package::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new PackageResource($item), trans('Package created successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Package::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Package not found'));
        }

        return $this->sendResponse(new PackageResource($item), trans('Package retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Package($input);

        return $this->sendResponse(new PackageResource($item), null);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Package::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Package not found'));
        }

        $input = $request->all();

        $rules = Package::getRules($id);

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

        return $this->sendResponse(new PackageResource($item), trans('Package updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Package::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Package not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Package deleted successfully'));
    }
}
