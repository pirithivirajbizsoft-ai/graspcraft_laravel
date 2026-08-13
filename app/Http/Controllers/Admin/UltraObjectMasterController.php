<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UltraObjectMasterService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Port of graspcraft_backend/src/modules/admin/ultra-object-master/ultra_object_master.controller.ts. */
class UltraObjectMasterController extends Controller
{
    public function __construct(private readonly UltraObjectMasterService $service) {}

    public function create(Request $request): JsonResponse
    {
        try {
            $data = $this->service->create(
                $this->normalise($request, defaultObjectIds: []),
                $request->file('file'),
            );

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findAll(Request $request): JsonResponse
    {
        try {
            $data = $this->service->findAll($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOne(string $id): JsonResponse
    {
        try {
            $data = $this->service->findOne($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            // On update the default is null, not [] — an absent object_ids must
            // leave the existing mapping alone.
            $data = $this->service->update(
                $id,
                $this->normalise($request, defaultObjectIds: null),
                $request->file('file'),
            );

            return ApiResponse::success(Messages::SC001, Messages::SM002, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function remove(string $id): JsonResponse
    {
        try {
            $data = $this->service->remove($id);

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    /**
     * multipart sends arrays either as repeated fields or as a JSON string, and
     * booleans as "true"/"false". The Nest controller does exactly this coercion
     * inline before handing the DTO to the service.
     *
     * @return array<string, mixed>
     */
    private function normalise(Request $request, ?array $defaultObjectIds): array
    {
        $data = $request->except('file');

        $objectIds = $data['object_ids'] ?? null;

        if (is_array($objectIds)) {
            $data['object_ids'] = $objectIds;
        } elseif (is_string($objectIds) && $objectIds !== '') {
            $data['object_ids'] = json_decode($objectIds, true) ?? [];
        } else {
            $data['object_ids'] = $defaultObjectIds;
        }

        if (isset($data['status'])) {
            $data['status'] = filter_var($data['status'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? $data['status'];
        }

        return $data;
    }
}
