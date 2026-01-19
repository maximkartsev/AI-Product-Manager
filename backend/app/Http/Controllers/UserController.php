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

class UserController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, [
            'name',
            'email',
        ]);

        $orderStr = $request->get('order', 'name:asc');

        $filters = $this->extractFilters($request, User::class);

        $this->addFiltersCriteria($query, $filters, User::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => UserResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => [],
        ];

        return $this->sendResponse($response, trans('Users retrieved successfully'));
    }

    public function store(Request $request)
    {
        $input = $request->all();

        $validator = Validator::make($input, [
            'name' => 'required',
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        $input['password'] = bcrypt($input['password']);

        $item = User::create($input);

        $result['token'] = ($item->createToken('MyApp'))->plainTextToken;

        return $this->sendResponse(new UserResource($item), trans('User created successfully'));
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
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
    public function update(Request $request, $id)
    {
        $item = User::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('User not found'));
        }

        $input = $request->all();

        if ($request->has('password')) {
            $input['password'] = bcrypt($input['password']);
        }

        $item->fill($input);

        $item->save();

        $item = $item->fresh();

        return $this->sendResponse(new UserResource($item), trans('User updated successfully'));
    }
}
