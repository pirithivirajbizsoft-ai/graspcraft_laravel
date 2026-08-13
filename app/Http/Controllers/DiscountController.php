<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateDiscountRequest;
use App\Http\Requests\UpdateDiscountRequest;
use App\Services\DiscountService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Port of graspcraft_backend/src/modules/discount/discount.controller.ts. */
class DiscountController extends Controller
{
    public function __construct(private readonly DiscountService $discountService) {}

    public function create(CreateDiscountRequest $request): JsonResponse
    {
        try {
            $data = $this->discountService->create($request->whitelisted());

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOne(string $id): JsonResponse
    {
        try {
            $data = $this->discountService->findOne($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findAmount(string $code): JsonResponse
    {
        try {
            $data = $this->discountService->findAmount($code);

            // An unknown code is a user-facing rejection, not a failure.
            if (! $data) {
                return ApiResponse::error(Messages::ER001, Messages::EM018, null);
            }

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function update(UpdateDiscountRequest $request, string $id): JsonResponse
    {
        try {
            $data = $this->discountService->update($id, $request->whitelisted());

            return ApiResponse::success(Messages::SC001, Messages::SM002, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function remove(string $id): JsonResponse
    {
        try {
            $data = $this->discountService->remove($id);

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findAll(Request $request): JsonResponse
    {
        try {
            $data = $this->discountService->findAll($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }
}
