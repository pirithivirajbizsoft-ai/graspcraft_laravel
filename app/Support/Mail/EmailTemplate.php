<?php

namespace App\Support\Mail;

/**
 * Port of graspcraft_backend/src/utils/EmailTemplete.ts.
 *
 * This is customer-facing HTML that real people receive, so it is copied
 * verbatim rather than rewritten into a Blade view — including the inline
 * styles, the emoji, the "FaceCraft" branding and the 2025 copyright line.
 * Changing any of it is a product decision, not a porting one.
 */
class EmailTemplate
{
    public const SUBJECT = '📸 Your Photo Copy is Ready!';

    /**
     * @param  array<string, mixed>  $data  the SendMailDto: order_id, customer_id, name, email_id
     * @param  string|null  $url  EMAILSENT_LINK — the PANEL's /image_viewer route
     */
    public static function photoCopy(array $data, ?string $url): string
    {
        $user = $data['name'] ?? '';
        $orderId = $data['order_id'] ?? '';
        $customerId = $data['customer_id'] ?? '';

        $userHtml = e($user);
        $link = e($url.'?order_id='.$orderId.'&customer_id='.$customerId);

        return <<<HTML

        <!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Your Photo Copy is Ready</title>
</head>
<body style="margin:0; padding:0; font-family:Arial, sans-serif; background-color:#f4f4f4;">
  <div style="max-width:600px; margin:30px auto; background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.1);">
    <div style="background:linear-gradient(135deg, #6a11cb, #2575fc); color:#fff; padding:20px 30px;">
      <h2 style="margin:0; font-size:24px;">📸 Your Photo Copy is Ready!</h2>
    </div>

    <div style="padding:30px;">
      <p style="font-size:16px; color:#333;">Hi <strong>{$userHtml}</strong>,</p>
      <p style="font-size:16px; color:#333;">Thank you for using our service. Below is the link to view your photo copy:</p>

      <div style="margin:30px 0; text-align:center;">
          <a href="{$link}" target="_blank"
            style="background-color:#2575fc; color:#fff; padding:12px 24px; border-radius:6px; text-decoration:none; font-size:16px; font-weight:bold; display:inline-block;">
            🔗 Click to View Your Photo
          </a>
      </div>

      <p style="font-size:14px; color:#888;">If you didn’t request this, please ignore this email.</p>
      <p style="font-size:14px; color:#aaa;">– The FaceCraft Team</p>
    </div>

    <div style="background-color:#f1f1f1; padding:15px 30px; text-align:center; font-size:12px; color:#999;">
      © 2025 FaceCraft. All rights reserved.
    </div>
  </div>
</body>
</html>

HTML;
    }
}
