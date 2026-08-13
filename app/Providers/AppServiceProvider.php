<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureMailer();
    }

    /**
     * app.use(rateLimit({ windowMs: 60_000, max: RATE_LIMIT_MAX ?? 300 })) in
     * main.ts.
     *
     * Requests per IP per minute. One admin screen issues well over 30 calls, so
     * this has to be generous enough for a person clicking around; it is here to
     * stop abuse, not to pace normal use.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('photocraft.rate_limit_max', 300))
                ->by($request->ip())
                ->response(fn () => response('Too many requests, please try again later.', 429));
        });
    }

    /**
     * MailUtils.ts builds its nodemailer transport from the SMTP_* vars on every
     * send. Those names are kept (see config/photocraft.php) so a deployed .env
     * copies across unchanged; this maps them onto Laravel's mailer at boot
     * rather than requiring a second set of MAIL_* entries.
     */
    private function configureMailer(): void
    {
        $smtp = config('photocraft.smtp');

        if (empty($smtp['host'])) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $smtp['host'],
            'mail.mailers.smtp.port' => $smtp['port'],
            // nodemailer: `secure: false` for 587 — STARTTLS, not implicit TLS.
            'mail.mailers.smtp.encryption' => $smtp['port'] === 465 ? 'tls' : null,
            'mail.mailers.smtp.username' => $smtp['username'],
            'mail.mailers.smtp.password' => $smtp['password'],
            'mail.from.address' => $smtp['from'],
        ]);
    }
}
