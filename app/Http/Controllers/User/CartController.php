<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\CartService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Port of graspcraft_backend/src/modules/user/cart/cart.controller.ts. */
class CartController extends Controller
{
    public function __construct(private readonly CartService $cartService) {}

    public function create(Request $request): JsonResponse
    {
        try {
            $data = $this->cartService->create($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findAll(string $customerId): JsonResponse
    {
        try {
            $data = $this->cartService->findAll($customerId);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function remove(string $cartId): JsonResponse
    {
        try {
            $data = $this->cartService->deleteOneCart($cartId);

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function removeMultiple(string $customerId): JsonResponse
    {
        try {
            $data = $this->cartService->deleteMultipleCart($customerId);

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function getOneOrder(string $orderId): JsonResponse
    {
        try {
            $data = $this->cartService->getOneOrder($orderId);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }
}
