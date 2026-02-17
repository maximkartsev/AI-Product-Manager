<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\TokenTransaction;
use App\Models\TokenWallet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;

class UsersController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $this->addSearchCriteria($searchStr, $query, ['name', 'email']);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, User::class, ['name', 'email', 'is_admin', 'created_at', 'updated_at']);

        $this->addFiltersCriteria($query, $filters, User::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => $items,
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, 'Users retrieved successfully');
    }

    public function show(Request $request, $id): JsonResponse
    {
        $user = User::find($id);

        if (is_null($user)) {
            return $this->sendError('User not found');
        }

        $tenant = Tenant::where('user_id', $id)->first();

        $data = $user->toArray();
        $data['tenant'] = $tenant ? [
            'id' => $tenant->getKey(),
            'domain' => $tenant->domains->first()?->domain ?? null,
            'db_pool' => $tenant->db_pool ?? null,
        ] : null;

        return $this->sendResponse($data, 'User retrieved successfully');
    }

    public function purchases(Request $request, $id): JsonResponse
    {
        $user = User::find($id);

        if (is_null($user)) {
            return $this->sendError('User not found');
        }

        $perPage = abs((int) $request->get('perPage', 20));
        $page = abs((int) $request->get('page', 1));
        $from = ($page - 1) * $perPage;

        $query = Purchase::where('user_id', $id)->with('payment')->orderBy('id', 'desc');

        $totalRows = (clone $query)->count();
        $items = $query->offset($from)->limit($perPage)->get();

        return $this->sendResponse([
            'items' => $items,
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
        ], 'Purchases retrieved successfully');
    }

    public function tokens(Request $request, $id): JsonResponse
    {
        $user = User::find($id);

        if (is_null($user)) {
            return $this->sendError('User not found');
        }

        $tenant = Tenant::where('user_id', $id)->first();

        if (!$tenant) {
            return $this->sendResponse([
                'balance' => 0,
                'items' => [],
                'totalItems' => 0,
                'totalPages' => 0,
                'page' => 1,
                'perPage' => 20,
            ], 'No tenant found for user');
        }

        $perPage = abs((int) $request->get('perPage', 20));
        $page = abs((int) $request->get('page', 1));
        $from = ($page - 1) * $perPage;

        $tenancy = app(Tenancy::class);
        $tenancy->initialize($tenant);

        try {
            $wallet = TokenWallet::where('tenant_id', (string) $tenant->getKey())->first();
            $balance = $wallet ? (int) $wallet->balance : 0;

            $query = TokenTransaction::where('tenant_id', (string) $tenant->getKey())
                ->orderBy('id', 'desc');

            $totalRows = (clone $query)->count();
            $items = $query->offset($from)->limit($perPage)->get();

            return $this->sendResponse([
                'balance' => $balance,
                'items' => $items,
                'totalItems' => $totalRows,
                'totalPages' => ceil($totalRows / $perPage),
                'page' => $page,
                'perPage' => $perPage,
            ], 'Token data retrieved successfully');
        } finally {
            $tenancy->end();
        }
    }
}
