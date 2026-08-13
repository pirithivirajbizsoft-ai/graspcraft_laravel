<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateProductRequest;
use App\Http\Requests\CreateProductSizeRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Services\ProductsService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Port of graspcraft_backend/src/modules/admin/products/products.controller.ts. */
class ProductsController extends Controller
{
    public function __construct(private readonly ProductsService $productsService) {}

    public function create(CreateProductRequest $request): JsonResponse
    {
        try {
            $data = $this->productsService->create($request->whitelisted());

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOne(string $id): JsonResponse
    {
        try {
            $data = $this->productsService->findOne($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        try {
            $data = $this->productsService->update($id, $request->whitelisted());

            return ApiResponse::success(Messages::SC001, Messages::SM002, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function remove(string $id): JsonResponse
    {
        try {
            $data = $this->productsService->remove($id);

            // Still mapped to a combo — the admin has to detach it first.
            if ($data === false) {
                return ApiResponse::error(Messages::ER001, Messages::EM019, null);
            }

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findAll(Request $request): JsonResponse
    {
        try {
            $data = $this->productsService->findAll($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    // ─── product size ────────────────────────────────────────────────────────

    public function createProductSize(CreateProductSizeRequest $request): JsonResponse
    {
        try {
            $data = $this->productsService->createProductSize($request->whitelisted());

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function productSizeById(string $id): JsonResponse
    {
        try {
            $data = $this->productsService->productSizeById($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function updateProductSize(CreateProductSizeRequest $request, string $id): JsonResponse
    {
        try {
            $data = $this->productsService->updateProductSize($id, $request->whitelisted());

            return ApiResponse::success(Messages::SC001, Messages::SM002, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function removeProductSize(string $id): JsonResponse
    {
        try {
            $data = $this->productsService->removeProductSize($id);

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findAllProductSize(Request $request): JsonResponse
    {
        try {
            $data = $this->productsService->findAllProductSize($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }
}
