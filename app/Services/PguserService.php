<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\FramImgMap;
use App\Models\ImagesUpload;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AwsConfig;
use App\Support\Enums\UploadType;
use App\Support\Enums\UsersType;
use App\Support\LocalImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Port of graspcraft_backend/src/modules/user/pguser/pguser.service.ts. */
class PguserService
{
    /**
     * Upload a batch of files to the bucket for their upload type, and write the
     * follow-on rows that type implies.
     *
     * @param  UploadedFile[]  $files
     * @param  string|null  $photoToFrameMap  JSON string sent alongside the files
     * @return array<mixed>
     */
    public function uploads3(
        array $files,
        UploadType $uploadType,
        ?string $userId = null,
        ?string $photoToFrameMap = null,
    ): array {
        $localStorage = new LocalImageStorage;
        $uploaded = [];
        $imagesUploads = [];

        ['date' => $date, 'time' => $time] = ApiResponse::formatDateTime();

        $photoToFrameMaps = json_decode($photoToFrameMap ?: '[]', true) ?: [];

        /*
         * The same query-string id is used for both a photographer and a customer,
         * so which column it lands in is decided by whether a customers row exists
         * with that id.
         */
        $userIdColumn = [];
        if ($userId) {
            $customerUpload = Customer::query()->where('id', $userId)->first();
            $userIdColumn = $customerUpload?->id
                ? ['customer_id' => $userId]
                : ['user_id' => $userId];
        }

        /*
         * A raw capture (uploadType image) shares one endpoint between the App
         * and the Kiosk, so which local folder it belongs in is not known from
         * the upload type alone — it's resolved once per request from the
         * uploading user's user_type. Everything else has a fixed folder.
         */
        $captureFolder = $uploadType === UploadType::IMAGE
            ? $this->resolveCaptureFolder($userIdColumn)
            : null;

        $failure = null;
        $successCount = 0;

        foreach ($files as $index => $file) {
            try {
                $frameMap = $photoToFrameMaps[$index] ?? null;
                $random8DigitString = (string) random_int(10000000, 99999999);
                $extension = $file->getClientOriginalExtension();

                /*
                 * Branch on uploadType, never on the local folder: several upload
                 * types now resolve to the SAME 'web-master' folder, so a name
                 * comparison would fire for the wrong type.
                 *
                 * The prefix puts each master in its own folder inside
                 * 'web-master'. It is empty for image (see resolveCaptureFolder())
                 * and for framedImages (client supplies the filename).
                 */
                $filename = ($uploadType === UploadType::FRAMED_IMAGES && $frameMap)
                    ? ($frameMap['merged_img_name'] ?? null)
                    : $uploadType->prefix().$random8DigitString.'.'.$extension;

                $folder = $uploadType === UploadType::IMAGE ? $captureFolder : $uploadType->localFolder();
                $key = $folder ? $folder.'/'.$filename : $filename;

                $body = file_get_contents($file->getRealPath());

                $localStorage->store($key, $body);

                if ($uploadType === UploadType::IMAGE) {
                    // ExternalImageId can't contain '/', so index by the flat
                    // filename, not the folder-qualified key. getMatchedImages()
                    // matches on that same flat filename.
                    (new AwsConfig)->indexFaceBytes($filename, $body);
                }

                $uploaded[] = $key;
                $imagesUploads[] = $userIdColumn + [
                    'date' => $date,
                    'time' => $time,
                    'img_url' => $key,
                ];
                $successCount++;
            } catch (\Throwable $e) {
                $failure = $e->getMessage();
            }
        }

        if ($failure !== null) {
            return [
                'uploaded' => $uploaded,
                'successCount' => $successCount,
                'total' => count($files),
                'error' => $failure,
            ];
        }

        /*
         * Only a raw photographer capture belongs in images-uploads. Keying this
         * off the bucket name would insert a row for every frame, product and
         * avatar upload once those types share a bucket, which would corrupt the
         * customer gallery, the photographer dashboard counts and the reports.
         */
        if ($uploadType === UploadType::IMAGE) {
            foreach ($imagesUploads as $row) {
                ImagesUpload::create($row);
            }
        }

        if ($uploadType === UploadType::FRAMED_IMAGES && $photoToFrameMaps !== []) {
            $customerCode = $photoToFrameMaps[0]['customer_code'] ?? null;

            $existCustomer = Customer::query()
                ->select(['id'])
                ->where('customer_code', $customerCode)
                ->first();

            $customerId = $existCustomer?->id
                ?? Customer::create(['customer_code' => $customerCode])->id;

            $framedFolder = UploadType::FRAMED_IMAGES->localFolder();

            $created = [];
            DB::transaction(function () use ($photoToFrameMaps, $customerId, $framedFolder, &$created) {
                foreach ($photoToFrameMaps as $data) {
                    $mergedImgName = $data['merged_img_name'] ?? null;

                    $created[] = FramImgMap::create([
                        'customer_id' => $customerId,
                        'frame_id' => $data['frame_id'] ?? null,
                        'frame_name' => $data['frame_name'] ?? null,
                        'org_img_id' => $data['org_img_id'] ?? null,
                        'org_img_name' => $data['org_img_name'] ?? null,
                        // Must match the local storage key the file was actually
                        // written to above ($framedFolder.'/'.$filename).
                        'merged_img_name' => $mergedImgName ? $framedFolder.'/'.$mergedImgName : null,
                    ]);
                }
            });

            // Returns the created rows, NOT the uploaded-count shape below.
            return $created;
        }

        /*
         * The array-wrapped shape below is a frontend contract, not an accident:
         * the ads/avatar screens read res.data[0].uploaded[0], while frame and
         * productcombo read res.data.uploaded[0]. Keep them as they are.
         */
        if ($uploadType === UploadType::FRAMED_IMAGES || $uploadType === UploadType::ADS) {
            return [[
                'uploaded' => $uploaded,
                'successCount' => $successCount,
                'total' => count($files),
            ]];
        }

        // image, frame, productcombo
        return [
            'uploaded' => $uploaded,
            'successCount' => $successCount,
            'total' => count($files),
        ];
    }

    /**
     * Admin view of one photographer's uploads.
     *
     * @return array{pg_user: ?User, count: int, pg_uploads: mixed}
     */
    public function getImagesByPguser(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $user = User::query()->where('id', $req['pguser_id'])->first();

        $query = ImagesUpload::query()->where('user_id', $req['pguser_id']);

        if (! empty($req['date'])) {
            $query->where('date', $req['date']);
        }

        if (! empty($req['time'])) {
            $query->where('time', $req['time']);
        }

        $count = (clone $query)->count();

        $rows = $query->orderByDesc('created_at');

        // Pagination is only applied when BOTH were supplied, matching the Node
        // `pageNo && limit ? {limit, offset} : {}`.
        if (! empty($req['page_no']) && ! empty($req['limit'])) {
            $rows->offset($offset)->limit($limit);
        }

        return ['pg_user' => $user, 'count' => $count, 'pg_uploads' => $rows->get()];
    }

    /**
     * Photographer dashboard: lifetime and today's upload counts.
     *
     * `date` is a dd-mm-yyyy STRING, so "today" needs TO_DATE rather than a
     * timestamp comparison.
     */
    public function dashboard(string $pguserId)
    {
        /*
         * ::text on the counts: COUNT() is bigint, which node-postgres hands to
         * JS as a STRING, so Sequelize gives the panel "0"/"12". PHP's pdo_pgsql
         * would return native ints and flip the type. Casting in SQL keeps both
         * back ends emitting the same JSON.
         */
        return ImagesUpload::query()
            ->selectRaw('COUNT(id)::text AS total_count')
            ->selectRaw(<<<'SQL'
                COUNT(CASE WHEN TO_DATE("date", 'DD-MM-YYYY') = CURRENT_DATE THEN 1 END)::text AS today_count
            SQL)
            ->where('user_id', $pguserId)
            ->get();
    }

    /** Upload batches, newest first — one row per (time, date, created_at). */
    public function getHistory(string $pguserId)
    {
        return ImagesUpload::query()
            ->selectRaw('COUNT(id)::text AS upload_count')
            ->addSelect('time')
            ->selectRaw('MIN(date) AS date')
            ->where('user_id', $pguserId)
            ->groupBy('time', 'date', 'created_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /** @return string[] */
    public function getTimeByDate(string $date, string $id): array
    {
        return ImagesUpload::query()
            ->distinct()
            ->where('date', $date)
            ->where('user_id', $id)
            ->pluck('time')
            ->all();
    }

    /** Hard delete — `force: true`. */
    public function deleteMultipleImages(string $userId, array $data): int
    {
        return ImagesUpload::query()
            ->where('user_id', $userId)
            ->whereIn('id', $data['img_ids'] ?? [])
            ->forceDelete();
    }

    /**
     * Store a retouched photo and point the fram-img-map row at it.
     *
     * @return array<string, mixed> an `error` key means a handled rejection
     */
    public function saveEditedFrame(string $frameImgId, ?UploadedFile $file): array
    {
        if (! $file) {
            return ['error' => 'edited image file is required'];
        }

        $framedImg = FramImgMap::query()->where('id', $frameImgId)->first();

        if (! $framedImg) {
            return ['error' => 'frame image not found'];
        }

        $localStorage = new LocalImageStorage;
        $extension = $file->getClientOriginalExtension();
        $random8DigitString = (string) random_int(10000000, 99999999);
        $key = UploadType::FRAMED_IMAGES->localFolder()."/edited_{$random8DigitString}.{$extension}";

        try {
            // Retouched output never goes through indexFaceBytes — it must NOT
            // be indexed as a new face.
            $localStorage->store($key, file_get_contents($file->getRealPath()));
        } catch (\Throwable) {
            return ['error' => 'failed to store edited image'];
        }

        FramImgMap::query()
            ->where('id', $frameImgId)
            ->update(['edited_img_name' => $key, 'is_edited' => true]);

        // so the kiosk can show the edited image immediately
        $url = $localStorage->url($key);

        return [
            'frame_img_id' => $frameImgId,
            'edited_img_name' => $key,
            'url' => $url,
        ];
    }

    /**
     * Which local top-level folder a raw capture (uploadType image) belongs in.
     *
     * The App and the Kiosk share one upload endpoint with no explicit "source"
     * field, so this is inferred from the uploading user's user_type: a
     * photographer is the App, everything else (kiosk, staff, no resolvable
     * user, or a customer upload) falls back to 'kiosk' — the endpoint is
     * physically tied to the kiosk hardware by default.
     *
     * @param  array{user_id?: string}|array{customer_id?: string}  $userIdColumn
     */
    private function resolveCaptureFolder(array $userIdColumn): string
    {
        if (isset($userIdColumn['user_id'])) {
            $userType = User::query()->where('id', $userIdColumn['user_id'])->value('user_type');

            if ($userType === UsersType::PHOTOGRAPHER->value) {
                return 'app';
            }
        }

        return 'kiosk';
    }
}
