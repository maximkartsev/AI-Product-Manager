<?php

namespace App\Http\Controllers;

use App\Http\Resources\Record as RecordResource;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as Validator;

class RecordController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Record::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['title','description','recorded_at']);

        $orderStr = $request->get('order','id:asc');

        $filters = $this->extractFilters($request,Record::class);

        $this->addFiltersCriteria($query,$filters,Record::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => RecordResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows/$perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response,trans('Records retrieved successfully'));
    }

   /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Record::getRules());

        if($validator->fails()){
            return $this->sendError(trans('Validation Error'), $validator->errors(),422);
        }

        try{
            $item = Record::create($input);
        }catch(\Exception $e){
            return $this->sendError($e->getMessage(),[],409);
        }

        return $this->sendResponse(new RecordResource($item),trans('Record created successfully'), [], 201);
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $item = Record::find($id);

        if(is_null($item)){
            return $this->sendError(trans('Record not found'));
        }

        return $this->sendResponse(new RecordResource($item),trans('Record retrieved successfully'));

    }

     /**
     * Show the form for creating a new resource
     *
     * @param  \Illuminate\Http\Request  $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Record($input);

        return $this->sendResponse(new RecordResource($item),null);
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
        $item = Record::find($id);

        if(is_null($item)){
            return $this->sendError(trans('Record not found'));
        }

        $input = $request->all();

         $rules = Record::getRules($id);

        foreach ($rules as $k => $v) {
            if (!array_key_exists($k, $input)) {
                unset($rules[$k]);
            }
        }

        $validator = Validator::make($input,$rules);

        if($validator->fails()){
            return $this->sendError(trans('Validation Error'), $validator->errors(),422);
        }

        $item->fill($input);

        try{
            $item->save();
        }catch(\Exception $e){
            return $this->sendError($e->getMessage(),[],409);
        }

        $item->fresh();

        return $this->sendResponse(new RecordResource($item), trans('Record updated successfully'));

    }


    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $item = Record::find($id);

        if(is_null($item)){
            return $this->sendError(trans('Record not found'));
        }

        try{
            $item->delete();
        }catch(\Exception $e){
            return $this->sendError($e->getMessage(),[],409);
        }

        return $this->sendNoContent();

    }
}
