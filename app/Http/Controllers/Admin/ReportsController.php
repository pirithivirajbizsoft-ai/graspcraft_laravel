<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderPaginationRequest;
use App\Services\ReportsService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Port of graspcraft_backend/src/modules/admin/reports/reports.controller.ts. */
class ReportsController extends Controller
{
    public function __construct(private readonly ReportsService $reportsService) {}

    public function kioskReport(OrderPaginationRequest $request): JsonResponse
    {
        try {
            $data = $this->reportsService->kioskReport($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function photographerReport(OrderPaginationRequest $request): JsonResponse
    {
        try {
            $data = $this->reportsService->photographerReport($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function overallReport(OrderPaginationRequest $request): JsonResponse
    {
        try {
            $data = $this->reportsService->overallReport($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function staffReport(OrderPaginationRequest $request): JsonResponse
    {
        try {
            $data = $this->reportsService->staffReport($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function getCashOrders(Request $request): JsonResponse
    {
        try {
            $data = $this->reportsService->getCashOrders($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function getDeletedOrders(Request $request): JsonResponse
    {
        try {
            $data = $this->reportsService->getDeletedOrders($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    /** Archives to deleted_orders, then hard deletes. */
    public function deleteOrders(Request $request, string $orderId): JsonResponse
    {
        try {
            $data = $this->reportsService->deleteOrders($orderId, $request->query('deleted_by'));

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }
}
