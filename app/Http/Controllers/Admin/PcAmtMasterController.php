<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePcAmtMasterRequest;
use App\Http\Requests\UpdatePcAmtMasterRequest;
use App\Services\PcAmtMasterService;
use App\Support\ApiResponse;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Port of graspcraft_backend/src/modules/admin/pc_amt_master/pc_amt_master.controller.ts. */
class PcAmtMasterController extends Controller
{
    public function __construct(private readonly PcAmtMasterService $pcAmtMasterService) {}

    public function create(CreatePcAmtMasterRequest $request): JsonResponse
    {
        try {
            $data = $this->pcAmtMasterService->create($request->whitelisted());

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findAll(Request $request): JsonResponse
    {
        try {
            $data = $this->pcAmtMasterService->findAll($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOne(string $id): JsonResponse
    {
        try {
            $data = $this->pcAmtMasterService->findOne($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function update(UpdatePcAmtMasterRequest $request, string $id): JsonResponse
    {
        try {
            $data = $this->pcAmtMasterService->update($id, $request->whitelisted());

            return ApiResponse::success(Messages::SC001, Messages::SM002, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function remove(string $id): JsonResponse
    {
        try {
            $data = $this->pcAmtMasterService->remove($id);

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }
}
