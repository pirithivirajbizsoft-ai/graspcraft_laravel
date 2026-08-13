<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReqCollectionRequest;
use App\Support\ApiResponse;
use App\Support\AwsConfig;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;

/**
 * Port of graspcraft_backend/src/modules/aws/aws.controller.ts.
 *
 * Rekognition collection administration only. The commented-out s3-upload and
 * compare-faces routes in the Node controller are not ported — the live upload
 * path is PguserController::uploads3 and the live face match is
 * UsersController::compareUserFaces.
 */
class AwsController extends Controller
{
    public function createCollection(ReqCollectionRequest $request): JsonResponse
    {
        try {
            $data = (new AwsConfig)->createCollection($request->input('req_Col_id'));

            return ApiResponse::created(Messages::SC001, Messages::SM001, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function listCollection(): JsonResponse
    {
        try {
            $data = (new AwsConfig)->listCollection();

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function listCollectionFaces(ReqCollectionRequest $request): JsonResponse
    {
        try {
            $data = (new AwsConfig)->listFaces($request->input('req_Col_id'));

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function deleteCollection(ReqCollectionRequest $request): JsonResponse
    {
        try {
            $data = (new AwsConfig)->deleteCollection($request->input('req_Col_id'));

            return ApiResponse::created(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }
}
