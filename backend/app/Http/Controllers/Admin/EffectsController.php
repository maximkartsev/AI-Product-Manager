<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BaseController;
use App\Http\Resources\Effect as EffectResource;
use App\Models\Effect;
use App\Services\PresignedUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator as Validator;
use Illuminate\Support\Str;

class EffectsController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Effect::query()->with('category');

        [$perPage, $page, $fieldsToSelect, $searchStr, $from] = $this->buildParamsFromRequest($request, $query);

        $query->select($fieldsToSelect);

        $searchFields = array_merge(['name', 'slug', 'description', 'type'], $this->getRelationSearchFields(Effect::class));
        $this->addSearchCriteria($searchStr, $query, $searchFields);

        $orderStr = $request->get('order', 'id:asc');

        $filters = $this->extractFilters($request, Effect::class);

        $this->addFiltersCriteria($query, $filters, Effect::class);

        [$totalRows, $items] = $this->addCountQueryAndExecute($orderStr, $query, $from, $perPage);

        $response = [
            'items' => EffectResource::collection($items),
            'totalItems' => $totalRows,
            'totalPages' => ceil($totalRows / $perPage),
            'page' => $page,
            'perPage' => $perPage,
            'order' => $orderStr,
            'search' => $searchStr,
            'filters' => $filters,
        ];

        return $this->sendResponse($response, trans('Effects retrieved successfully'));
    }

    public function show($id): JsonResponse
    {
        $item = Effect::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Effect not found'));
        }

        return $this->sendResponse(new EffectResource($item), trans('Effect retrieved successfully'));
    }

    public function create(Request $request): JsonResponse
    {
        $item = new Effect();

        return $this->sendResponse(new EffectResource($item), null);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, Effect::getRules());

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 422);
        }

        try {
            $item = Effect::create($input);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendResponse(new EffectResource($item), trans('Effect created successfully'), [], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $item = Effect::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Effect not found'));
        }

        $input = $request->all();

        $rules = Effect::getRules($id);

        foreach ($rules as $k => $v) {
            if (!array_key_exists($k, $input)) {
                unset($rules[$k]);
            }
        }

        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 422);
        }

        $item->fill($input);

        try {
            $item->save();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        $item->fresh();

        return $this->sendResponse(new EffectResource($item), trans('Effect updated successfully'));
    }

    public function destroy($id): JsonResponse
    {
        $item = Effect::find($id);

        if (is_null($item)) {
            return $this->sendError(trans('Effect not found'));
        }

        try {
            $item->delete();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), [], 409);
        }

        return $this->sendNoContent();
    }

    public function createUpload(Request $request, PresignedUrlService $presigned): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kind' => 'string|required|in:workflow,thumbnail,preview_video',
            'mime_type' => 'string|required|max:255',
            'size' => 'integer|required|min:1',
            'original_filename' => 'string|required|max:512',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation error.', $validator->errors(), 422);
        }

        $originalFilename = (string) $request->input('original_filename');
        if (!$this->isSafeFilename($originalFilename)) {
            return $this->sendError('Invalid filename.', [], 422);
        }

        $kind = (string) $request->input('kind');
        $mimeType = strtolower((string) $request->input('mime_type'));
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if ($extension === '') {
            $extension = match ($kind) {
                'workflow' => 'json',
                'thumbnail' => 'png',
                'preview_video' => 'mp4',
                default => 'bin',
            };
        }

        $path = match ($kind) {
            'workflow' => sprintf('resources/comfyui/workflows/admin/%s.%s', (string) Str::uuid(), $extension),
            'thumbnail' => sprintf('effects/thumbnails/%s.%s', (string) Str::uuid(), $extension),
            'preview_video' => sprintf('effects/previews/%s.%s', (string) Str::uuid(), $extension),
            default => sprintf('effects/unknown/%s.%s', (string) Str::uuid(), $extension),
        };

        $disk = $kind === 'workflow'
            ? (string) config('services.comfyui.workflow_disk', 's3')
            : (string) config('filesystems.default', 's3');
        $ttlSeconds = (int) config('services.comfyui.presigned_ttl_seconds', 900);

        try {
            $upload = $presigned->uploadUrl($disk, $path, $ttlSeconds, $mimeType);
        } catch (\Throwable $e) {
            return $this->sendError('Upload URL generation failed.', [], 500);
        }

        $data = [
            'path' => $path,
            'upload_url' => $upload['url'] ?? null,
            'upload_headers' => $upload['headers'] ?? [],
            'expires_in' => $ttlSeconds,
        ];

        if ($kind !== 'workflow') {
            $data['public_url'] = Storage::disk($disk)->url($path);
        }

        return $this->sendResponse($data, 'Upload initialized');
    }

    private function isSafeFilename(string $filename): bool
    {
        if ($filename === '') {
            return false;
        }

        if (Str::contains($filename, ['..', '/', '\\'])) {
            return false;
        }

        return basename($filename) === $filename;
    }
}
