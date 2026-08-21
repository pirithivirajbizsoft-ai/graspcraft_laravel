<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\MigrateStockRequest;
use App\Services\Inventory\StockMigrationService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** New functionality, not a port of a graspcraft_backend controller. */
class StockMigrationController extends Controller
{
    public function __construct(private readonly StockMigrationService $stockMigrationService) {}

    public function findAll(Request $request): JsonResponse
    {
        try {
            $data = $this->stockMigrationService->findAll($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOne(string $orderId): JsonResponse
    {
        try {
            $data = $this->stockMigrationService->findOne($orderId);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function migrate(MigrateStockRequest $request): JsonResponse
    {
        try {
            $dto = $request->whitelisted();
            $data = $this->stockMigrationService->migrate($dto['order_ids'], $dto['warehouse_id']);

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\RuntimeException $e) {
            return ApiResponse::errorCreated(Messages::ER002, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }
}
