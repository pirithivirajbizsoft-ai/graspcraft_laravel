<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderPaginationRequest;
use App\Services\StaffCodeError;
use App\Services\UsersService;
use App\Support\ApiResponse;
use App\Support\AwsConfig;
use App\Support\Messages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Port of graspcraft_backend/src/modules/user/users/users.controller.ts.
 *
 * Every action follows the same shape as the Nest original: call the service,
 * wrap the result in the envelope, and turn ANY throw into an errorResponse that
 * still goes out with a success HTTP status. See App\Support\ApiResponse.
 *
 * POST actions use ApiResponse::created() because Nest answers @Post with 201.
 */
class UsersController extends Controller
{
    public function __construct(private readonly UsersService $usersService) {}

    public function create(Request $request): JsonResponse
    {
        try {
            $data = $this->usersService->create($request->all());

            // Note: SM004 ("fetched"), not SM001 — matching the Node original.
            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findAll(Request $request): JsonResponse
    {
        try {
            $data = $this->usersService->findAll($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function checkExistUser(Request $request): JsonResponse
    {
        try {
            $data = $this->usersService->checkExistUser($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function emailedImages(Request $request): JsonResponse
    {
        try {
            $data = $this->usersService->getEmailedImages($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOne(string $id): JsonResponse
    {
        try {
            $data = $this->usersService->findOne($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function getKioskUserId(Request $request): JsonResponse
    {
        try {
            $data = $this->usersService->getKioskByUserId($request->query('user_id'));

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $data = $this->usersService->update($id, $request->all());

            return ApiResponse::success(Messages::SC001, Messages::SM002, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function remove(string $id): JsonResponse
    {
        try {
            $data = $this->usersService->remove($id);

            return ApiResponse::success(Messages::SC001, Messages::SM003, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function customerRegistor(Request $request): JsonResponse
    {
        try {
            $data = $this->usersService->customerRegistor($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function getCustomerImages(Request $request): JsonResponse
    {
        try {
            $data = $this->usersService->customerImages($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function deleteCustomerImages(Request $request): JsonResponse
    {
        try {
            $data = $this->usersService->deleteCustomerImages($request->all());

            // SM004 ("fetched"), not SM003 — matching the Node original.
            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function userLogin(Request $request): JsonResponse
    {
        try {
            $data = $this->usersService->userLogin($request->all());

            // A wrong username/password is not an exception — the service returns
            // false and the panel shows EM006 from the envelope.
            if ($data === false) {
                return ApiResponse::errorCreated(Messages::ER001, Messages::EM006, null);
            }

            // SM004, not SM006 — the Node original never uses SM006.
            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function userLogout(): JsonResponse
    {
        try {
            $this->usersService->userLogout();

            // The Node controller discards the service result and sends null.
            return ApiResponse::success(Messages::SC001, Messages::SM014, null);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    /**
     * Kiosk face match.
     *
     * multipart/form-data: `file` is the captured photo and `photoFrameRequest`
     * a JSON string carrying date / from_time / to_time.
     */
    public function compareUserFaces(Request $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $bytes = $file ? file_get_contents($file->getRealPath()) : null;

            $data = $this->usersService->compareImages($request->all(), $bytes);

            // No active frame is configured, so the kiosk cannot compose anything.
            if ($data === 'noframe') {
                return ApiResponse::errorCreated(Messages::ER001, Messages::SM011, []);
            }

            // 'noimage' and 'nothing' are unreachable in the current service —
            // kept because the Node controller branches on them and a future
            // change to compareImages could start returning them again.
            if ($data === 'noimage') {
                return ApiResponse::errorCreated(Messages::ER001, Messages::SM012, []);
            }

            if ($data === 'nothing') {
                return ApiResponse::errorCreated(Messages::ER001, Messages::SM013, []);
            }

            // Rekognition errored or found nothing usable in the supplied photo.
            if (! $data) {
                return ApiResponse::errorCreated(Messages::ER001, Messages::EM013, null);
            }

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            Log::error('Error in compare face api ----------->', ['e' => $e->getMessage()]);

            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function getFrameWithImages(string $customerId): JsonResponse
    {
        try {
            $data = $this->usersService->getFrameWithImages($customerId);

            if ($data === false) {
                return ApiResponse::error(Messages::ER001, Messages::EM014, null);
            }

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function createOrder(Request $request): JsonResponse
    {
        try {
            $data = $this->usersService->createOrder($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (StaffCodeError $e) {
            /*
             * A bad staff ID is the operator's mistake, not a server failure, so it
             * carries ER002 (400) and the specific message rather than the generic
             * EM008 — the kiosk shows it verbatim so the ID can be corrected.
             */
            return ApiResponse::errorCreated(Messages::ER002, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function updateCustomer(Request $request, string $customerId): JsonResponse
    {
        try {
            $data = $this->usersService->updateCustomer($customerId, $request->all());

            // ER001, not ER002 — the Node original uses the server code here even
            // though the cause is a bad staff ID.
            if ($data === false) {
                return ApiResponse::error(Messages::ER001, Messages::EM017, null);
            }

            return ApiResponse::success(Messages::SC001, Messages::SM002, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function updateOrder(Request $request, string $orderId): JsonResponse
    {
        try {
            $data = $this->usersService->updateOrder($orderId, $request->all());

            return ApiResponse::success(Messages::SC001, Messages::SM002, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findAllOrders(OrderPaginationRequest $request): JsonResponse
    {
        try {
            $data = $this->usersService->findAllOrders($request->all());

            return ApiResponse::created(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::errorCreated(Messages::ER001, Messages::EM008, $e);
        }
    }

    public function findOneOrder(string $id): JsonResponse
    {
        try {
            $data = $this->usersService->findOneOrder($id);

            return ApiResponse::success(Messages::SC001, Messages::SM004, $data);
        } catch (\Throwable $e) {
            return ApiResponse::error(Messages::ER001, Messages::EM008, $e);
        }
    }

    /**
     * Stream the order's emailed photos as a zip.
     *
     * This route does NOT use the envelope — it writes the archive straight to
     * the response, and answers 404 with a bare {status, msg} when the order has
     * no emailed images. Both shapes are what the panel's download handler
     * expects.
     */
    public function downloadImagesZip(Request $request): StreamedResponse|JsonResponse
    {
        $orderId = $request->input('order_id');

        try {
            ['imageNames' => $imageNames] = $this->usersService->zipImageNames($orderId);
        } catch (\Throwable $e) {
            // The Node catch answers a REAL 500 with the raw message — this route
            // is outside the envelope entirely.
            Log::error('ZIP ERROR: '.$e->getMessage());

            return response()->json(['status' => false, 'msg' => $e->getMessage()], 500);
        }

        Log::info('Images found for zip', ['count' => count($imageNames), 'names' => $imageNames]);

        if ($imageNames === []) {
            return response()->json([
                'status' => false,
                'msg' => 'No images found for this order',
            ], 404);
        }

        $aws = new AwsConfig;

        // ZipArchive needs a real file, so the archive is built in a temp file and
        // streamed out. JSZip's DEFLATE level 6 is ZipArchive's default.
        $tmpPath = tempnam(sys_get_temp_dir(), 'order-zip-');

        $zip = new \ZipArchive;
        $zip->open($tmpPath, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);

        foreach ($imageNames as $imgName) {
            try {
                $zip->addFromString($imgName, $aws->downloadImgBuffer($imgName));
            } catch (\Throwable $e) {
                // One unreachable object must not fail the whole download — the
                // Node version logs and carries on too.
                Log::error("Failed to fetch image {$imgName}: ".$e->getMessage());
            }
        }

        $zip->close();

        return response()->streamDownload(
            function () use ($tmpPath) {
                readfile($tmpPath);
                @unlink($tmpPath);
            },
            "order-{$orderId}-images.zip",
            [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) filesize($tmpPath),
            ],
        );
    }
}
