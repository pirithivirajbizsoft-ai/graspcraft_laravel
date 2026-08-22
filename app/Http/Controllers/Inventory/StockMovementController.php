<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\StockMovementService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only stock ledger + warehouse-wise stock report. New functionality,
 * not a port of a graspcraft_backend controller.
 */
class StockMovementController extends Controller
{
    public function __construct(private readonly StockMovementService $stockMovementService) {}

    public function findAll(Request $request): JsonResponse
    {
        try {
            $data = $this->stockMovementService->findAll($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOne(string $id): JsonResponse
    {
        try {
            $data = $this->stockMovementService->findOne($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    /** On-hand quantity lookup for the Stock In/Out screens. */
    public function itemInfo(Request $request): JsonResponse
    {
        try {
            $data = $this->stockMovementService->itemInfo(
                (string) $request->query('item_id'),
                (string) $request->query('warehouse_id'),
            );

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    /** Current on-hand quantity per Inventory Item per warehouse. */
    public function warehouseStock(Request $request): JsonResponse
    {
        try {
            $data = $this->stockMovementService->warehouseStock($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }
}
