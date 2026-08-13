<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderPaginationRequest;
use App\Services\AppService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Port of graspcraft_backend/src/app.controller.ts. */
class AppController extends Controller
{
    public function __construct(private readonly AppService $appService) {}

    /** Returns a bare string, not the envelope. */
    public function getHello(): string
    {
        return $this->appService->getHello();
    }

    public function adminDashboard(OrderPaginationRequest $request): JsonResponse
    {
        try {
            $data = $this->appService->adminDashboard($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function sendPhotoEmail(Request $request): JsonResponse
    {
        try {
            $data = $this->appService->sendPhotoEmail($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function downloadFramedImg(string $img): JsonResponse
    {
        try {
            $data = $this->appService->downloadFramedImg($img);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }
}
