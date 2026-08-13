<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PayoutRequest;
use App\Services\CommissionService;
use App\Services\PayoutValidationError;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Port of graspcraft_backend/src/modules/admin/commission/commission.controller.ts. */
class CommissionController extends Controller
{
    public function __construct(private readonly CommissionService $commissionService) {}

    public function getCommissions(Request $request): JsonResponse
    {
        try {
            $data = $this->commissionService->getCommissions($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function payout(PayoutRequest $request): JsonResponse
    {
        try {
            $data = $this->commissionService->payout($request->whitelisted());

            return ApiResponse::created(Messages::SC001, Messages::SM002, $data);
        } catch (PayoutValidationError $e) {
            // Rejections the admin can act on carry their own message.
            return ApiResponse::errorCreated(Messages::ER001, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function getPayouts(Request $request): JsonResponse
    {
        try {
            $data = $this->commissionService->getPayouts($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }
}
