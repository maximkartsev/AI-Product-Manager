<?php

namespace App\Http\Controllers;

use App\Http\Resources\Effect as EffectResource;
use App\Models\Effect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as Validator;

class EffectController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $items = Effect::query()
            ->where('is_active', true)
            ->with(['category'])
            ->orderBy('id', 'asc')
            ->get();

        return $this->sendResponse(EffectResource::collection($items), trans('Effects retrieved successfully'));
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

        $validator = Validator::make($input, Effect::getRules());

        if($validator->fails()){
            return $this->sendError(trans('Validation Error'), $validator->errors(),400);
        }

        try{
            $item = Effect::create($input);
        }catch(\Exception $e){
            return $this->sendError($e->getMessage(),[],409);
        }

        $item->load(['aiModel']);

        return $this->sendResponse(new EffectResource($item),trans('Effect created successfully'));
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
            ->with(['category']);

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

     /**
     * Show the form for creating a new resource
     *
     * @param  \Illuminate\Http\Request  $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $input = $request->all();

        $item = new Effect($input);

        $item->load(['aiModel']);

        return $this->sendResponse(new EffectResource($item),null);
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
        $item = Effect::find($id);

        if(is_null($item)){
            return $this->sendError(trans('Effect not found'));
        }

        $input = $request->all();

         $rules = Effect::getRules($id);

        foreach ($rules as $k => $v) {
            if (!array_key_exists($k, $input)) {
                unset($rules[$k]);
            }
        }

        $validator = Validator::make($input,$rules);

        if($validator->fails()){
            return $this->sendError(trans('Validation Error'), $validator->errors(),400);
        }

        $item->fill($input);

        try{
            $item->save();
        }catch(\Exception $e){
            return $this->sendError($e->getMessage(),[],409);
        }

        $item->fresh();

        $item->load(['aiModel']);

        return $this->sendResponse(new EffectResource($item), trans('Effect updated successfully'));

    }


    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $item = Effect::find($id);

        if(is_null($item)){
            return $this->sendError(trans('Effect not found'));
        }

        try{
            $item->delete();
        }catch(\Exception $e){
            return $this->sendError($e->getMessage(),[],409);
        }

        return $this->sendResponse([], trans('Effect deleted successfully'));

    }
}
