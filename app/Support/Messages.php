<?php

namespace App\Support;

/**
 * Port of the message and code constants in
 * graspcraft_backend/src/utils/index.ts.
 *
 * The strings are part of the API contract — the Angular panel shows several of
 * them verbatim — so they are copied exactly, typos and trailing dots included.
 */
final class Messages
{
    // ─── success messages ────────────────────────────────────────────────────
    public const SM001 = 'Data created successfully!';

    public const SM002 = 'Data updated successfully!';

    public const SM003 = 'Data deleted successfully!';

    public const SM004 = 'Data fetched successfully!';

    public const SM005 = 'File uploaded successfully!';

    public const SM006 = 'Login successful!';

    public const SM007 = 'Logout successful!';

    public const SM008 = 'Operation completed successfully!';

    public const SM009 = 'Password changed successfully!';

    public const SM010 = 'Email sent successfully!';

    public const SM011 = 'No frames found...!';

    public const SM012 = 'No images found...!';

    public const SM013 = 'No frames and images found...!';

    public const SM014 = 'Logout Succesfully...!';

    // ─── error messages ──────────────────────────────────────────────────────
    public const EM001 = 'Failed to create data.';

    public const EM002 = 'Failed to update data.';

    public const EM003 = 'Failed to delete data.';

    public const EM004 = 'Failed to fetch data.';

    public const EM005 = 'File upload failed.';

    public const EM006 = 'Invalid credentials.';

    public const EM007 = 'Access denied.';

    public const EM008 = 'Something went wrong. Please try again.';

    public const EM009 = 'Resource not found.';

    public const EM010 = 'Validation failed.';

    public const EM011 = 'Internal server error.';

    public const EM012 = 'Unauthorized access.';

    public const EM013 = 'No faces were detected in the given images....';

    public const EM014 = "Both products and combo does't exist...";

    public const EM015 = 'please select atleast one photo ....';

    public const EM016 = 'Already added to cart ....';

    public const EM017 = "Staff id does'nt Exit";

    public const EM018 = 'Invalid code...!';

    public const EM019 = 'This product is mapped to a combo, so you cannot delete it';

    // ─── environment names ───────────────────────────────────────────────────
    public const DEVELOPMENT = 'dev';

    public const LOCAL = 'local';

    public const PRODUCTION = 'prod';

    // ─── response codes carried in the body, not the HTTP status ─────────────
    public const SC001 = 200;

    public const ER001 = 500;

    /**
     * Client-side rejection: the request itself is wrong (e.g. an unknown staff
     * ID), as opposed to ER001 which means the server failed.
     */
    public const ER002 = 400;
}
