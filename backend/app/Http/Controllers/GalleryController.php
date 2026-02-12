<?php

namespace App\Http\Controllers;

use App\Http\Resources\GalleryVideoResource;
use App\Models\GalleryVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GalleryController extends BaseController
{
    /**
     * Display a listing of public gallery videos.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = GalleryVideo::query()->where('is_public', true);

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['tags', 'effect>name']);

        $orderStr = $request->get('order', 'created_at:desc');

        $filters = $this->extractFilters($request, GalleryVideo::class);

        $this->addFiltersCriteria($query, $filters, GalleryVideo::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $items->load([
            'effect:id,slug,name,description,type,is_premium,category_id,credits_cost',
            'effect.category:id,slug,name,description',
        ]);

        $response = [
            'items' => GalleryVideoResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => (int) ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Gallery retrieved successfully'));
    }

    /**
     * Display a single public gallery entry.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $item = GalleryVideo::query()
            ->where('is_public', true)
            ->with([
                'effect:id,slug,name,description,type,is_premium,category_id,credits_cost',
                'effect.category:id,slug,name,description',
            ])
            ->find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Gallery video not found'), [], 404);
        }

        return $this->sendResponse(new GalleryVideoResource($item), trans('Gallery video retrieved successfully'));
    }
}
