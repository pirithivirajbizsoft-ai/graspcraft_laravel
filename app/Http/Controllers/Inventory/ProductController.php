<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\CreateProductRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Services\Inventory\ProductService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * New functionality, not a port of a graspcraft_backend controller. Follows
 * the same envelope/try-catch shape as every other controller in this app
 * (see FramesController), with one addition: a RuntimeException raised by
 * the service is a known business-rule rejection (duplicate name,
 * insufficient stock, etc.) and is shown to the user via its own message,
 * rather than the generic "something went wrong" every other \Throwable
 * gets.
 */
class ProductController extends Controller
{
    public function __construct(private readonly ProductService $productService) {}

    public function create(CreateProductRequest $request): JsonResponse
    {
        try {
            $data = $this->productService->create($request->whitelisted());

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
            $data = $this->productService->findAll($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOne(string $id): JsonResponse
    {
        try {
            $data = $this->productService->findOne($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        try {
            $data = $this->productService->update($id, $request->whitelisted());

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
            $data = $this->productService->remove($id);

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\RuntimeException $e) {
            return ApiResponse::error(Messages::ER002, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    /** Feeds the unit dropdown on the Stock In/Out screens. */
    public function uomFamily(string $id): JsonResponse
    {
        try {
            $data = $this->productService->uomFamily($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }
}
