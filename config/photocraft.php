<?php

/**
 * Every setting the Node backend read straight off process.env, gathered here.
 *
 * The env KEY NAMES are deliberately unchanged from graspcraft_backend/.env so
 * a deployed server's env file can be copied across without a rename pass. That
 * includes NODE_ENV, which still drives the dev/prod credential and bucket
 * split exactly as it did in awsConfig.ts and PguserService.getBucketByType.
 */
$nodeEnv = env('NODE_ENV', 'local');

// awsConfig.ts: `process.env.NODE_ENV === PRODUCTION`, where PRODUCTION is the
// literal 'prod' — not 'production'. An env of "production" is NOT prod here,
// which is the existing behaviour and is relied on by the dev deployment.
$isProd = $nodeEnv === 'prod';

return [
    'node_env' => $nodeEnv,
    'is_prod' => $isProd,

    'jwt_secret' => env('JWT_SECRET'),

    /*
     * Link put into the customer's soft-copy email. It points at the PANEL app's
     * /image_viewer route, not at the API, and it is customer-facing.
     */
    'emailsent_link' => env('EMAILSENT_LINK'),

    /*
     * Requests per IP per minute. One admin screen issues well over 30 calls, so
     * this has to be generous enough for a person clicking around; it is here to
     * stop abuse, not to pace normal use.
     */
    'rate_limit_max' => (int) env('RATE_LIMIT_MAX', 300),

    /*
     * Browsers reject "*" together with credentials, and a wildcard lets any site
     * call this API from a logged-in user's browser. Set CORS_ORIGINS on each
     * server to that environment's panel origin, comma separated.
     */
    'cors_origins' => env('CORS_ORIGINS', ''),

    'aws' => [
        'region' => env('AWS_REGION'),

        /*
         * Dev and prod live in separate IAM users whose policies are scoped to
         * their own buckets, so the credentials switch with NODE_ENV exactly like
         * the bucket names below do. Using the dev key against the prod buckets
         * is not a partial failure — it is AccessDenied on every single upload.
         */
        'key' => $isProd ? env('AWS_PROD_ACCESSKEY_ID') : env('AWS_ACCESSKEY_ID'),
        'secret' => $isProd ? env('AWS_PROD_SECRET_ACCESSKEY_ID') : env('AWS_SECRET_ACCESSKEY_ID'),

        /* Rekognition face collection. Renaming this empties the face index. */
        'collection_id' => $isProd
            ? env('AWS_REQ_COLLECTIONID_PROD')
            : env('AWS_REQ_COLLECTIONID'),

        /*
         * Path to a CA bundle for verifying the AWS endpoints' TLS certificates.
         *
         * Leave unset on a normal Linux server - the system trust store is used
         * and this is not needed. It exists for Windows PHP builds, which ship no
         * CA bundle at all and fail every AWS call with "unable to get local
         * issuer certificate" until curl is pointed at one.
         *
         * Setting it here rather than in php.ini keeps the workaround inside the
         * project instead of in the developer's PHP install.
         */
        'ca_bundle' => env('AWS_CA_BUNDLE'),
    ],

    /*
     * Three buckets, split purely by retention:
     *   images        raw photographer captures, 1-day lifecycle expiry
     *   framed_images completed / framed order images, 7-day lifecycle expiry
     *   masters       frames, products, combos, object masters, staff avatars —
     *                 permanent. frame / productcombo / ads all point at it.
     *
     * Both the plain and _prod variants are exposed because UploadType::bucket()
     * picks between them from config('photocraft.is_prod').
     */
    'buckets' => [
        'images' => env('AWS_BUCKET_NAME'),
        'images_prod' => env('AWS_BUCKET_NAME_PROD'),

        'framed_images' => env('AWS_FRAMED_IMAGES_BUCKET'),
        'framed_images_prod' => env('AWS_FRAMED_IMAGES_BUCKET_PROD'),

        'frame' => env('AWS_FRAME_BUCKET'),
        'frame_prod' => env('AWS_FRAME_BUCKET_PROD'),

        'productcombo' => env('AWS_PRODUCTCOMBO_BUCKET'),
        'productcombo_prod' => env('AWS_PRODUCTCOMBO_BUCKET_PROD'),

        'ads' => env('AWS_ADS_BUCKET'),
        'ads_prod' => env('AWS_ADS_BUCKET_PROD'),
    ],

    'smtp' => [
        'host' => env('SMTP_HOST'),
        'port' => (int) env('SMTP_PORT', 587),
        'username' => env('SMTP_USERNAME'),
        'password' => env('SMTP_PASSWORD'),
        'from' => env('SMTP_FROM_EMAIL'),
    ],
];
