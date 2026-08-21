<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\CreateUomRequest;
use App\Http\Requests\Inventory\UpdateUomRequest;
use App\Services\Inventory\UomService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** New functionality, not a port of a graspcraft_backend controller. */
class UomController extends Controller
{
    public function __construct(private readonly UomService $uomService) {}

    public function create(CreateUomRequest $request): JsonResponse
    {
        try {
            $data = $this->uomService->create($request->whitelisted());

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\RuntimeException $e) {
            return ApiResponse::errorCreated(Messages::ER002, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findAll(Request $request): JsonResponse
    {
        try {
            $data = $this->uomService->findAll($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOne(string $id): JsonResponse
    {
        try {
            $data = $this->uomService->findOne($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    /** Populates a "base unit" dropdown when creating a derived unit. */
    public function baseUnits(): JsonResponse
    {
        try {
            $data = $this->uomService->baseUnits();

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function update(UpdateUomRequest $request, string $id): JsonResponse
    {
        try {
            $data = $this->uomService->update($id, $request->whitelisted());

            return ApiResponse::success(Messages::SC001, Messages::SM002, $data);
        } catch (\RuntimeException $e) {
            return ApiResponse::error(Messages::ER002, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function remove(string $id): JsonResponse
    {
        try {
            $data = $this->uomService->remove($id);

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\RuntimeException $e) {
            return ApiResponse::error(Messages::ER002, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }
}
