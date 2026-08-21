<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockOutRequest;
use App\Services\Inventory\StockOutService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;

/** New functionality, not a port of a graspcraft_backend controller. */
class StockOutController extends Controller
{
    public function __construct(private readonly StockOutService $stockOutService) {}

    public function create(StockOutRequest $request): JsonResponse
    {
        try {
            $data = $this->stockOutService->create($request->whitelisted());

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\RuntimeException $e) {
            return ApiResponse::errorCreated(Messages::ER002, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }
}
