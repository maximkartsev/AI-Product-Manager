<?php

namespace App\Http\Controllers;

use App\Http\Resources\Category as CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends BaseController
{
    /**
     * Display a listing of categories (public).
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description']);

        $orderStr = $request->get('order', 'sort_order:asc,name:asc');

        $filters = $this->extractFilters($request, Category::class);

        $this->addFiltersCriteria($query, $filters, Category::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => CategoryResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => (int) ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Categories retrieved successfully'));
    }

    /**
     * Display the specified category by slug or id.
     *
     * @param mixed $slugOrId
     * @return JsonResponse
     */
    public function show($slugOrId): JsonResponse
    {
        $query = Category::query();

        if (is_numeric($slugOrId)) {
            $item = $query->whereKey((int) $slugOrId)->first();
        } else {
            $item = $query->where('slug', (string) $slugOrId)->first();
        }

        if (is_null($item)) {
            return $this->sendError(trans('Category not found'), [], 404);
        }

        return $this->sendResponse(new CategoryResource($item), trans('Category retrieved successfully'));
    }
}
