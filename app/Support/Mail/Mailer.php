<?php

namespace App\Support\Mail;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Port of graspcraft_backend/src/utils/MailUtils.ts.
 *
 * One behaviour here is deliberately preserved even though it looks like a bug:
 * sendEmail() SWALLOWS failures. The Node version wraps the whole thing in
 * try/catch and only console.logs, and it also passes a callback to
 * transporter.sendMail, so the outer await returns before the send resolves.
 * The practical effect is that SendPhotoEmail() returns success whether or not
 * the mail actually went out.
 *
 * Making it throw would turn a currently-succeeding request into a failure the
 * moment SMTP credentials expire, which the panel is not written to handle.
 * Failures are logged instead, same as today.
 */
class Mailer
{
    /**
     * @param  string|string[]  $to
     * @param  array<int, array{filename: string, content: mixed}>  $attachments
     */
    public function send(
        string|array $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $attachments = [],
    ): void {
        try {
            Mail::html($html, function ($message) use ($to, $subject, $text, $attachments) {
                $message->to($to)->subject($subject);

                if ($text !== null) {
                    $message->text($text);
                }

                foreach ($attachments as $attachment) {
                    $message->attachData(
                        $attachment['content'],
                        $attachment['filename'],
                    );
                }
            });

            Log::info('Email sent successfully', [
                'at' => now()->toIso8601String(),
                'to' => $to,
            ]);
        } catch (\Throwable $e) {
            // Node: `console.log('Send_Email_Error: ' + error)` — never rethrown.
            Log::error('Send_Email_Error: '.$e->getMessage());
        }
    }

    /**
     * MailUtils.Mailsent(data, customerName, url).
     *
     * Note the Node signature takes `customerName` and never uses it — the
     * template reads the name off `data` instead. Kept for call-site parity.
     *
     * @param  array<string, mixed>  $data  SendMailDto
     */
    public function mailSent(array $data, ?string $customerName, ?string $url): void
    {
        $this->send(
            to: $data['email_id'] ?? '',
            subject: EmailTemplate::SUBJECT,
            html: EmailTemplate::photoCopy($data, $url),
        );
    }
}
