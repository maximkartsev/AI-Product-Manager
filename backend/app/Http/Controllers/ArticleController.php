<?php

namespace App\Http\Controllers;

use App\Http\Resources\Article as ArticleResource;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as Validator;

class ArticleController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Article::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['title', 'sub_title', 'state', 'content', 'published_at']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Article::class);

        $this->addFiltersCriteria($query, $filters, Article::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $items->load(['user']);

        $response = [
            'items' => ArticleResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Articles retrieved successfully'));
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

        $validator = Validator::make($input, Article::getRules(), Article::getMessages());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 422);
        }

        try {
            $item = Article::create($input);
        } catch (\Exception $e) {
            \Log::error('Article creation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Article could not be created. Please try again or contact support.', [], 500);
        }

        $item->load(['user']);

        return $this->sendResponse(new ArticleResource($item), trans('Article created successfully'), [], 201);
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $item = Article::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Article not found'));
        }

        $item->load(['user']);

        return $this->sendResponse(new ArticleResource($item), trans('Article retrieved successfully'));

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

        $item = new Article($input);

        $item->load(['user']);

        return $this->sendResponse(new ArticleResource($item), null);
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
        $item = Article::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Article not found'));
        }

        $input = $request->all();

        $rules = Article::getRules($id);

        foreach ($rules as $k => $v) {
            if (!array_key_exists($k, $input)) {
                unset($rules[$k]);
            }
        }

        $validator = Validator::make($input, $rules, Article::getMessages());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 422);
        }

        $item->fill($input);

        try {
            $item->save();
        } catch (\Exception $e) {
            \Log::error('Article update failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Article could not be updated. Please try again or contact support.', [], 500);
        }

        $item->fresh();

        $item->load(['user']);

        return $this->sendResponse(new ArticleResource($item), trans('Article updated successfully'));

    }


    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $item = Article::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Article not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            \Log::error('Article deletion failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Article could not be deleted. Please try again or contact support.', [], 500);
        }

        return $this->sendNoContent();

    }
}
