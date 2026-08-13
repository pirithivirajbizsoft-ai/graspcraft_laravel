<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\PguserService;
use App\Support\ApiResponse;
use App\Support\Enums\UploadType;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Port of graspcraft_backend/src/modules/user/pguser/pguser.controller.ts. */
class PguserController extends Controller
{
    public function __construct(private readonly PguserService $pguserService) {}

    /**
     * multipart/form-data upload of one or more files.
     *
     * `uploadtype` is a path segment; `pguser_id` a query parameter;
     * `photoToFrameMap` a JSON string in the body.
     */
    public function uploads3(Request $request, string $uploadtype): JsonResponse
    {
        try {
            $type = UploadType::tryFrom($uploadtype);

            if (! $type) {
                /*
                 * Node passes an unknown type straight through: getBucketByType
                 * returns undefined, the S3 call rejects, and the service reports
                 * it in its `error` key. Failing here reaches the same envelope
                 * without a pointless round trip to AWS.
                 */
                return ApiResponse::errorCreated(
                    Messages::ER001,
                    "Unknown upload type: {$uploadtype}",
                    "Unknown upload type: {$uploadtype}",
                );
            }

            $data = $this->pguserService->uploads3(
                files: $request->file('files') ?? [],
                uploadType: $type,
                userId: $request->query('pguser_id'),
                photoToFrameMap: $request->input('photoToFrameMap'),
            );

            // `if (data.error) return errorResponse(ER001, data, data.error)` —
            // note the MESSAGE is the whole result object, not just the error.
            if (isset($data['error'])) {
                return ApiResponse::errorCreated(Messages::ER001, $data, $data['error']);
            }

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\Throwable $e) {
            Log::error('Error in s3 upload api ----------->'.$e->getMessage());

            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function getImagesByPguser(Request $request): JsonResponse
    {
        try {
            $data = $this->pguserService->getImagesByPguser($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function dashboard(string $pguserId): JsonResponse
    {
        try {
            $data = $this->pguserService->dashboard($pguserId);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function getHistory(string $pguserId): JsonResponse
    {
        try {
            $data = $this->pguserService->getHistory($pguserId);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function getTimeByDate(string $pguserId, string $date): JsonResponse
    {
        try {
            $data = $this->pguserService->getTimeByDate($date, $pguserId);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function removeMultiple(Request $request, string $userId): JsonResponse
    {
        try {
            $data = $this->pguserService->deleteMultipleImages($userId, $request->all());

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function saveEditedFrame(Request $request, string $frameImgId): JsonResponse
    {
        try {
            $data = $this->pguserService->saveEditedFrame($frameImgId, $request->file('file'));

            // Handled rejections ("file is required", "frame image not found")
            // carry their own message rather than the generic EM008.
            if (isset($data['error'])) {
                return ApiResponse::error(Messages::ER001, $data['error'], $data['error']);
            }

            return ApiResponse::success(Messages::SC001, Messages::SM001, $data);
        } catch (\Throwable $e) {
            Log::error('Error in save-edited-frame api ----------->'.$e->getMessage());

            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }
}
