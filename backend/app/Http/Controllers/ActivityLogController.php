<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLog as ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as Validator;

class ActivityLogController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['log_name','description','subject_type','causer_type','properties','changed_fields','properties_diff','batch_uuid','event']);

        $orderStr = $request->get('order','id:asc');

        $filters = $this->extractFilters($request,ActivityLog::class);

        $this->addFiltersCriteria($query,$filters,ActivityLog::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $items->load(['causer']);

        $response = [
            'items' => ActivityLogResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows/$perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response,trans('Activity Logs retrieved successfully'));
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

        $validator = Validator::make($input, ActivityLog::getRules());

        if($validator->fails()){
            return $this->sendError(trans('Validation Error'), $validator->errors(),422);
        }

        try{
            $item = ActivityLog::create($input);
        }catch(\Exception $e){
                        \Log::error('Activity log operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        $item->load(['causer']);

        return $this->sendResponse(new ActivityLogResource($item),trans('Activity Log created successfully'), [], 201);
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $item = ActivityLog::find($id);

        if(is_null($item)){
            return $this->sendError(trans('Activity Log not found'));
        }

        $item->load(['causer']);

        return $this->sendResponse(new ActivityLogResource($item),trans('Activity Log retrieved successfully'));

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

        $item = new ActivityLog($input);

        $item->load(['causer']);

        return $this->sendResponse(new ActivityLogResource($item),null);
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
        $item = ActivityLog::find($id);

        if(is_null($item)){
            return $this->sendError(trans('Activity Log not found'));
        }

        $input = $request->all();

         $rules = ActivityLog::getRules($id);

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
                        \Log::error('Activity log operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        $item->fresh();

        $item->load(['causer']);

        return $this->sendResponse(new ActivityLogResource($item), trans('Activity Log updated successfully'));

    }


    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $item = ActivityLog::find($id);

        if(is_null($item)){
            return $this->sendError(trans('Activity Log not found'));
        }

        try{
            $item->delete();
        }catch(\Exception $e){
                        \Log::error('Activity log operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->sendError('Operation could not be completed. Please try again or contact support.', [], 500);
        }

        return $this->sendNoContent();

    }
}
