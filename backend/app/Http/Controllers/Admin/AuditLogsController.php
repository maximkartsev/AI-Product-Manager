<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Models\WorkerAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('perPage', 20), 100);
        $page = max((int) $request->get('page', 1), 1);
        $search = $request->get('search');
        $orderStr = $request->get('order', 'created_at:desc');

        $query = WorkerAuditLog::query()
            ->leftJoin('comfy_ui_workers', 'worker_audit_logs.worker_id', '=', 'comfy_ui_workers.id')
            ->select([
                'worker_audit_logs.*',
                'comfy_ui_workers.display_name as worker_display_name',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('worker_audit_logs.worker_identifier', 'like', "%{$search}%")
                    ->orWhere('worker_audit_logs.event', 'like', "%{$search}%")
                    ->orWhere('worker_audit_logs.ip_address', 'like', "%{$search}%")
                    ->orWhere('comfy_ui_workers.display_name', 'like', "%{$search}%");
            });
        }

        // Filters
        if ($request->has('event')) {
            $events = is_array($request->get('event')) ? $request->get('event') : explode(',', $request->get('event'));
            $query->whereIn('worker_audit_logs.event', $events);
        }

        if ($request->has('worker_id')) {
            $query->where('worker_audit_logs.worker_id', (int) $request->get('worker_id'));
        }

        if ($request->has('from_date')) {
            $query->where('worker_audit_logs.created_at', '>=', $request->get('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('worker_audit_logs.created_at', '<=', $request->get('to_date'));
        }

        // Ordering
        [$orderField, $orderDir] = $this->parseOrder($orderStr);
        $query->orderBy("worker_audit_logs.{$orderField}", $orderDir);

        $total = $query->count();
        $items = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return $this->sendResponse([
            'items' => $items,
            'totalItems' => $total,
            'totalPages' => ceil($total / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $search,
            'filters' => [],
        ], 'Audit logs retrieved successfully');
    }

    private function parseOrder(string $orderStr): array
    {
        $parts = explode(':', $orderStr, 2);
        $field = $parts[0] ?? 'created_at';
        $dir = strtolower($parts[1] ?? 'desc');

        $allowedFields = ['id', 'created_at', 'event', 'worker_identifier', 'ip_address'];
        if (!in_array($field, $allowedFields, true)) {
            $field = 'created_at';
        }

        return [$field, $dir === 'asc' ? 'asc' : 'desc'];
    }
}
