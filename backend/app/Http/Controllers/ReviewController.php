<?php

namespace App\Http\Controllers;

use App\Http\Resources\Review as ReviewResource;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as Validator;

class ReviewController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Review::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['rating', 'comment', 'user>email']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Review::class);

        $this->addFiltersCriteria($query, $filters, Review::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $items->load(['user', 'record']);

        $response = [
            'items' => ReviewResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Reviews retrieved successfully'));
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

        $input['user_id'] = auth()->user()->id;

        $validator = Validator::make($input, Review::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        try {
            $item = Review::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        $item->load(['user', 'record']);

        return $this->sendResponse(new ReviewResource($item), trans('Review created successfully'));
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $item = Review::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Review not found'));
        }

        $item->load(['user', 'record']);

        return $this->sendResponse(new ReviewResource($item), trans('Review retrieved successfully'));

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

        $item = new Review($input);

        $item->load(['user', 'record']);

        return $this->sendResponse(new ReviewResource($item), null);
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
        $item = Review::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Review not found'));
        }

        if ($item->user_id !== auth()->user()->id) {
            return $this->sendError(trans('You do not have permission to update this review'), [], 403);
        }

        $input = $request->all();

        $rules = Review::getRules($id);

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

        $item->load(['user', 'record']);

        return $this->sendResponse(new ReviewResource($item), trans('Review updated successfully'));

    }


    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $item = Review::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Review not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse([], trans('Review deleted successfully'));

    }
}
