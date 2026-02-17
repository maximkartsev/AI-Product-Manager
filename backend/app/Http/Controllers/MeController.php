<?php

namespace App\Http\Controllers;

use App\Http\Resources\Client as ClientResource;
use App\Http\Resources\Contract as ContractResource;
use App\Models\Organization;
use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use Carbon\Carbon;
use DefStudio\Telegraph\Models\TelegraphChat;
use DefStudio\Telegraph\Telegraph;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Resources\User as UserResource;
use Illuminate\Support\Facades\Validator as Validator;

class MeController extends BaseController
{

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {

        $id = auth()->user()->id;

        $item = User::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('User not found'));
        }

        return $this->sendResponse(new UserResource($item), trans('User retrieved successfully'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function update(Request $request)
    {
        $id = auth()->user()->id;

        $item = User::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('User not found'));
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:8',
        ]);

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 422);
        }

        $input = $request->only(['name', 'first_name', 'last_name', 'email', 'password']);

        if (isset($input['password'])) {
            $input['password'] = bcrypt($input['password']);
        }

        $item->fill($input);

        $item->save();

        $item = $item->fresh();

        return $this->sendResponse(new UserResource($item), trans('User updated successfully'));
    }
}
