<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ObjectMasterService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Port of graspcraft_backend/src/modules/admin/object-master/object_master.controller.ts.
 *
 * create and update are multipart/form-data with an optional `file`.
 */
class ObjectMasterController extends Controller
{
    public function __construct(private readonly ObjectMasterService $objectMasterService) {}

    public function create(Request $request): JsonResponse
    {
        try {
            $data = $this->objectMasterService->create(
                $this->normalise($request),
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
            $data = $this->objectMasterService->findAll($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOne(string $id): JsonResponse
    {
        try {
            $data = $this->objectMasterService->findOne($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $data = $this->objectMasterService->update(
                $id,
                $this->normalise($request),
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
            $data = $this->objectMasterService->remove($id);

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    /**
     * multipart sends every field as a string, so `status` arrives as "true" /
     * "false". Nest's ValidationPipe has `transform: true` and coerces it from
     * the DTO's declared boolean type; there is no such coercion here.
     *
     * @return array<string, mixed>
     */
    private function normalise(Request $request): array
    {
        $data = $request->except('file');

        if (isset($data['status'])) {
            $data['status'] = filter_var($data['status'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? $data['status'];
        }

        return $data;
    }
}
