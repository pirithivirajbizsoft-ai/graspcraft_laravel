<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateComboRequest;
use App\Http\Requests\UpdateComboRequest;
use App\Services\ComboService;
use App\Services\CommissionValidationError;
use App\Services\Inventory\BomValidationError;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Port of graspcraft_backend/src/modules/admin/combo/combo.controller.ts. */
class ComboController extends Controller
{
    public function __construct(private readonly ComboService $comboService) {}

    public function create(CreateComboRequest $request): JsonResponse
    {
        try {
            $data = $this->comboService->create($request->whitelisted());

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (CommissionValidationError|BomValidationError $e) {
            // Commission/BOM validation failures carry a message the user can
            // act on; everything else keeps the generic EM008.
            return ApiResponse::errorCreated(Messages::ER001, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOne(string $id): JsonResponse
    {
        try {
            $data = $this->comboService->findOne($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function update(UpdateComboRequest $request, string $id): JsonResponse
    {
        try {
            $data = $this->comboService->update($id, $request->whitelisted());

            return ApiResponse::success(Messages::SC001, Messages::SM002, $data);
        } catch (CommissionValidationError|BomValidationError $e) {
            return ApiResponse::error(Messages::ER001, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function remove(string $id): JsonResponse
    {
        try {
            $data = $this->comboService->remove($id);

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findAll(Request $request): JsonResponse
    {
        try {
            $data = $this->comboService->findAll($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }
}
