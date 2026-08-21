<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockInRequest;
use App\Services\Inventory\StockInService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;

/** New functionality, not a port of a graspcraft_backend controller. */
class StockInController extends Controller
{
    public function __construct(private readonly StockInService $stockInService) {}

    public function create(StockInRequest $request): JsonResponse
    {
        try {
            $data = $this->stockInService->create($request->whitelisted());

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\RuntimeException $e) {
            return ApiResponse::errorCreated(Messages::ER002, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }
}
