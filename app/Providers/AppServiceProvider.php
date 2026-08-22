<?php

namespace App\Providers;

use App\Models\Combo;
use App\Models\InventoryProduct;
use App\Models\Product;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        $this->configureStockItemMorphMap();
    }

    /**
     * The Inventory module's stock ledger (StockMovement/StockBalance,
     * app/Models/Concerns/ReferencesStockItem.php) can point at either an
     * Inventory Item (Stock In/Out/Transfer) or a Combo (order-driven stock
     * deduction only — see StockMigrationService, never a user-facing
     * item-type choice). A morph map stores the short type string (rather
     * than a fully-qualified class name) in item_type.
     *
     * The same global map is also reused by BomItem::owner() (owner_type/
     * owner_id) — a different polymorphic relation, but Laravel's morph map
     * is one shared registry, so 'PRODUCT' is registered here too rather
     * than duplicating the map elsewhere. 'PRODUCT' is never a stock-ledger
     * item_type (Product has no stock of its own — see StockService).
     */
    private function configureStockItemMorphMap(): void
    {
        Relation::morphMap([
            'INVENTORY_PRODUCT' => InventoryProduct::class,
            'COMBO' => Combo::class,
            'PRODUCT' => Product::class,
        ]);
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
