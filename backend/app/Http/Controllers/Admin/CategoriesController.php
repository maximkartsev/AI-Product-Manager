<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Http\Resources\Category as CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as Validator;

class CategoriesController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Category::class);

        $this->addFiltersCriteria($query, $filters, Category::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => CategoryResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Categories retrieved successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Category::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Category not found'));
        }

        return $this->sendResponse(new CategoryResource($item), trans('Category retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $item = new Category();

        return $this->sendResponse(new CategoryResource($item), null);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Category::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 422);
        }

        try {
            $item = Category::create($input);
        } catch (\Exception $e) {
                        \Log::error('Category operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        return $this->sendResponse(new CategoryResource($item), trans('Category created successfully'), [], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Category::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Category not found'));
        }

        $input = $request->all();

        $rules = Category::getRules($id);

        foreach ($rules as $k => $v) {
            if (!array_key_exists($k, $input)) {
                unset($rules[$k]);
            }
        }

        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 422);
        }

        $item->fill($input);

        try {
            $item->save();
        } catch (\Exception $e) {
                        \Log::error('Category operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        $item->fresh();

        return $this->sendResponse(new CategoryResource($item), trans('Category updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Category::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Category not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
                        \Log::error('Category operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        return $this->sendNoContent();
    }
}
