<?php

namespace App\Http\Controllers;

use App\Http\Resources\Effect as EffectResource;
use App\Models\Effect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EffectController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Effect::query()
            ->where('is_active', true)
            ->with(['category', 'workflow']);

        $categorySlug = $request->get('category');
        if (is_string($categorySlug) && $categorySlug !== '') {
            $query->whereHas('category', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        }

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'slug', 'description', 'type']);

        $orderStr = $request->get('order', 'id:desc');

        $filters = $this->extractFilters($request, Effect::class);

        $this->addFiltersCriteria($query, $filters, Effect::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => EffectResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => (int) ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Effects retrieved successfully'));
    }



    /**
     * Display the specified resource.
     *
     * @param mixed $slugOrId
     * @return JsonResponse
     */
    public function show($slugOrId): JsonResponse
    {
        $query = Effect::query()
            ->where('is_active', true)
            ->with(['category', 'workflow']);

        if (is_numeric($slugOrId)) {
            $item = $query->whereKey((int) $slugOrId)->first();
        } else {
            $item = $query->where('slug', (string) $slugOrId)->first();
        }

        if (is_null($item)) {
            return $this->sendError(trans('Effect not found'), [], 404);
        }

        return $this->sendResponse(new EffectResource($item), trans('Effect retrieved successfully'));

    }
}
