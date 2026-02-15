<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\ComfyUiWorker;
use App\Models\WorkerAuditLog;
use App\Services\WorkerAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WorkersController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = ComfyUiWorker::query()->withCount('workflows');

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $searchFields = ['worker_id', 'display_name'];
        $this->addSearchCriteria($searchStr, $query, $searchFields);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, ComfyUiWorker::class);
        $this->addFiltersCriteria($query, $filters, ComfyUiWorker::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        return $this->sendResponse([
            'items' => $items,
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ], 'Workers retrieved successfully');
    }

    public function show($id): JsonResponse
    {
        $worker = ComfyUiWorker::with('workflows')->withCount('workflows')->find($id);
        if (!$worker) {
            return $this->sendError('Worker not found');
        }

        $recentLogs = WorkerAuditLog::query()
            ->where('worker_id', $worker->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $data = $worker->toArray();
        $data['recent_audit_logs'] = $recentLogs;

        return $this->sendResponse($data, 'Worker retrieved successfully');
    }

    public function update(Request $request, $id): JsonResponse
    {
        $worker = ComfyUiWorker::find($id);
        if (!$worker) {
            return $this->sendError('Worker not found');
        }

        $validator = Validator::make($request->all(), [
            'display_name' => 'string|nullable|max:255',
            'is_draining' => 'boolean|nullable',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        if ($request->has('display_name')) {
            $worker->display_name = $request->input('display_name');
        }
        if ($request->has('is_draining')) {
            $worker->is_draining = (bool) $request->input('is_draining');
        }

        $worker->save();

        return $this->sendResponse($worker, 'Worker updated successfully');
    }

    public function approve(Request $request, $id, WorkerAuditService $audit): JsonResponse
    {
        $worker = ComfyUiWorker::find($id);
        if (!$worker) {
            return $this->sendError('Worker not found');
        }

        $worker->is_approved = true;
        $worker->save();

        $audit->log('approved', $worker->id, $worker->worker_id, null, $request->ip());

        return $this->sendResponse($worker, 'Worker approved');
    }

    public function revoke(Request $request, $id, WorkerAuditService $audit): JsonResponse
    {
        $worker = ComfyUiWorker::find($id);
        if (!$worker) {
            return $this->sendError('Worker not found');
        }

        $worker->is_approved = false;
        $worker->save();

        $audit->log('revoked', $worker->id, $worker->worker_id, null, $request->ip());

        return $this->sendResponse($worker, 'Worker approval revoked');
    }

    public function rotateToken(Request $request, $id, WorkerAuditService $audit): JsonResponse
    {
        $worker = ComfyUiWorker::find($id);
        if (!$worker) {
            return $this->sendError('Worker not found');
        }

        $plaintext = Str::random(64);
        $worker->token_hash = hash('sha256', $plaintext);
        $worker->save();

        $audit->log('token_rotated', $worker->id, $worker->worker_id, null, $request->ip());

        return $this->sendResponse([
            'token' => $plaintext,
            'message' => 'Save this token now. It will not be shown again.',
        ], 'Token rotated');
    }

    public function assignWorkflows(Request $request, $id): JsonResponse
    {
        $worker = ComfyUiWorker::find($id);
        if (!$worker) {
            return $this->sendError('Worker not found');
        }

        $validator = Validator::make($request->all(), [
            'workflow_ids' => 'present|array',
            'workflow_ids.*' => 'integer|exists:workflows,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $worker->workflows()->sync($request->input('workflow_ids', []));

        $worker->load('workflows');

        return $this->sendResponse($worker, 'Workflows assigned');
    }

    public function auditLogs(Request $request, $id): JsonResponse
    {
        $worker = ComfyUiWorker::find($id);
        if (!$worker) {
            return $this->sendError('Worker not found');
        }

        $perPage = min((int) $request->get('perPage', 20), 100);
        $page = max((int) $request->get('page', 1), 1);

        $query = WorkerAuditLog::query()
            ->where('worker_id', $worker->id)
            ->orderByDesc('created_at');

        $total = $query->count();
        $items = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return $this->sendResponse([
            'items' => $items,
            'totalItems' => $total,
            'totalPages' => ceil($total / $perPage),
            'page' => $page,
            'perPage' => $perPage,
        ], 'Audit logs retrieved');
    }
}
