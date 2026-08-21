<?php

use App\Http\Controllers\Admin\ComboController;
use App\Http\Controllers\Admin\CommissionController;
use App\Http\Controllers\Admin\FramesController;
use App\Http\Controllers\Admin\ObjectMasterController;
use App\Http\Controllers\Admin\PcAmtMasterController;
use App\Http\Controllers\Admin\ProductsController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\UltraObjectMasterController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AwsController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\Inventory\ProductController as InventoryProductController;
use App\Http\Controllers\Inventory\StockInController;
use App\Http\Controllers\Inventory\StockMigrationController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\Inventory\StockOutController;
use App\Http\Controllers\Inventory\StockTransferController;
use App\Http\Controllers\Inventory\UomController;
use App\Http\Controllers\Inventory\WarehouseController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\User\CartController;
use App\Http\Controllers\User\PguserController;
use App\Http\Controllers\User\UsersController;
use Illuminate\Support\Facades\Route;

/*
 * All routes carry the /api prefix, set in bootstrap/app.php to match
 * `app.setGlobalPrefix('api/')` in main.ts.
 *
 * The layout below mirrors the Nest module tree one @Controller at a time. Paths
 * are copied verbatim, including the ones that look like typos ('imges-upload',
 * 'customer-registor', 'delete-multiple-ph_images') and the trailing slash on
 * users/get-kiosk-userid/ — the Angular app calls them as they are.
 */

// ─── app.controller.ts (no controller prefix) ────────────────────────────────
Route::get('/', [AppController::class, 'getHello']);
Route::post('admin-dashboard', [AppController::class, 'adminDashboard']);
Route::post('admin-sendmail', [AppController::class, 'sendPhotoEmail']);
Route::get('download-framed-img/{img}', [AppController::class, 'downloadFramedImg']);

// ─── users ───────────────────────────────────────────────────────────────────
Route::prefix('users')->group(function () {
    Route::post('create-user', [UsersController::class, 'create']);
    Route::post('getall-users', [UsersController::class, 'findAll']);
    Route::post('check-exist-user', [UsersController::class, 'checkExistUser']);
    Route::post('emailed-images', [UsersController::class, 'emailedImages']);
    Route::get('getby-id/{id}', [UsersController::class, 'findOne']);

    /*
     * The Nest route is declared as 'get-kiosk-userid/' WITH a trailing slash.
     * Express matches both forms; Laravel does not, so both are registered.
     */
    Route::get('get-kiosk-userid', [UsersController::class, 'getKioskUserId']);
    Route::get('get-kiosk-userid/', [UsersController::class, 'getKioskUserId']);

    Route::patch('update-user/{id}', [UsersController::class, 'update']);
    Route::delete('delete-user/{id}', [UsersController::class, 'remove']);
    Route::post('customer-registor', [UsersController::class, 'customerRegistor']);
    Route::post('get-customer-images', [UsersController::class, 'getCustomerImages']);
    Route::delete('delete-customer-images', [UsersController::class, 'deleteCustomerImages']);
    Route::post('user-login', [UsersController::class, 'userLogin']);
    Route::get('user-logout', [UsersController::class, 'userLogout']);
    Route::post('compare-user-faces', [UsersController::class, 'compareUserFaces']);
    Route::get('get-frame-with-images/{customer_id}', [UsersController::class, 'getFrameWithImages']);
    Route::post('create-order', [UsersController::class, 'createOrder']);
    Route::patch('update-customer/{customer_id}', [UsersController::class, 'updateCustomer']);
    Route::patch('update-order/{order_id}', [UsersController::class, 'updateOrder']);
    Route::post('getall-orders', [UsersController::class, 'findAllOrders']);
    Route::get('get-order-by/{id}', [UsersController::class, 'findOneOrder']);
    Route::post('download-images-zip', [UsersController::class, 'downloadImagesZip']);
});

// ─── roles ───────────────────────────────────────────────────────────────────
Route::prefix('roles')->group(function () {
    Route::post('create-role', [RolesController::class, 'create']);
    Route::post('getall-roles', [RolesController::class, 'findAll']);
    Route::get('getby-id/{id}', [RolesController::class, 'findOne']);
    Route::patch('update-role/{id}', [RolesController::class, 'update']);
    Route::delete('delete-role/{id}', [RolesController::class, 'remove']);
});

// ─── cart ────────────────────────────────────────────────────────────────────
Route::prefix('cart')->group(function () {
    Route::post('create-cart', [CartController::class, 'create']);
    Route::get('getall-cart/{customer_id}', [CartController::class, 'findAll']);
    Route::delete('delete-single-cart/{cart_id}', [CartController::class, 'remove']);
    Route::delete('delete-multiple-cart/{customer_id}', [CartController::class, 'removeMultiple']);
    Route::get('get-order-by-id/{order_id}', [CartController::class, 'getOneOrder']);
});

// ─── pguser ──────────────────────────────────────────────────────────────────
Route::prefix('pguser')->group(function () {
    // 'imges-upload' is spelled that way in the Nest route and in the panel.
    Route::post('imges-upload/{uploadtype}', [PguserController::class, 'uploads3']);
    Route::post('get-upload-images-by-pguser', [PguserController::class, 'getImagesByPguser']);
    Route::get('dashboard/{pguser_id}', [PguserController::class, 'dashboard']);
    Route::get('get-history/{pguser_id}', [PguserController::class, 'getHistory']);
    Route::get('get-times-date/{pguser_id}/{date}', [PguserController::class, 'getTimeByDate']);
    Route::delete('delete-multiple-ph_images/{user_id}', [PguserController::class, 'removeMultiple']);
    Route::patch('save-edited-frame/{frame_img_id}', [PguserController::class, 'saveEditedFrame']);
});

// ─── aws (Rekognition collection admin) ──────────────────────────────────────
Route::prefix('aws')->group(function () {
    Route::post('create-rekognition-collection_id', [AwsController::class, 'createCollection']);
    Route::get('list-collection', [AwsController::class, 'listCollection']);
    Route::post('list-collection-faces', [AwsController::class, 'listCollectionFaces']);
    Route::post('delete-collection', [AwsController::class, 'deleteCollection']);
});

// ─── discount ────────────────────────────────────────────────────────────────
Route::prefix('discount')->group(function () {
    // @Post() with no path — POST /api/discount
    Route::post('/', [DiscountController::class, 'create']);
    Route::get('getby-id/{id}', [DiscountController::class, 'findOne']);
    Route::get('get-amount-by-code/{code}', [DiscountController::class, 'findAmount']);
    Route::patch('update-discount/{id}', [DiscountController::class, 'update']);
    Route::delete('delete-discount/{id}', [DiscountController::class, 'remove']);
    Route::post('getall-discount', [DiscountController::class, 'findAll']);
});

// ─── products (+ product size) ───────────────────────────────────────────────
Route::prefix('products')->group(function () {
    Route::post('create-product', [ProductsController::class, 'create']);
    Route::get('getby-id/{id}', [ProductsController::class, 'findOne']);
    Route::patch('update-product/{id}', [ProductsController::class, 'update']);
    Route::delete('delete-product/{id}', [ProductsController::class, 'remove']);
    Route::post('getall-products', [ProductsController::class, 'findAll']);

    Route::post('create-product-size', [ProductsController::class, 'createProductSize']);
    Route::get('getproduct-size-by-id/{id}', [ProductsController::class, 'productSizeById']);
    Route::patch('update-product-size/{id}', [ProductsController::class, 'updateProductSize']);
    Route::delete('delete-product-size/{id}', [ProductsController::class, 'removeProductSize']);
    Route::post('getall-products-size', [ProductsController::class, 'findAllProductSize']);
});

// ─── combo ───────────────────────────────────────────────────────────────────
Route::prefix('combo')->group(function () {
    Route::post('create-combo', [ComboController::class, 'create']);
    Route::get('getby-id/{id}', [ComboController::class, 'findOne']);
    Route::patch('update-combo/{id}', [ComboController::class, 'update']);
    Route::delete('delete-combo/{id}', [ComboController::class, 'remove']);
    Route::post('getall-combo', [ComboController::class, 'findAll']);
});

// ─── frames ──────────────────────────────────────────────────────────────────
Route::prefix('frames')->group(function () {
    Route::post('create-frame', [FramesController::class, 'create']);
    Route::post('getall-frames', [FramesController::class, 'findAll']);
    Route::get('getby-id/{id}', [FramesController::class, 'findOne']);
    Route::patch('update-frame/{id}', [FramesController::class, 'update']);
    Route::delete('delete-frame/{id}', [FramesController::class, 'remove']);
});

/*
 * ─── pc-amt-master ───────────────────────────────────────────────────────────
 * The only controller using bare @Post() / @Get(':id') style paths. `get-all`
 * MUST be registered before `{id}` or it would be captured as an id.
 */
Route::prefix('pc-amt-master')->group(function () {
    Route::post('/', [PcAmtMasterController::class, 'create']);
    Route::post('get-all', [PcAmtMasterController::class, 'findAll']);
    Route::get('{id}', [PcAmtMasterController::class, 'findOne']);
    Route::patch('{id}', [PcAmtMasterController::class, 'update']);
    Route::delete('{id}', [PcAmtMasterController::class, 'remove']);
});

// ─── object-master (multipart create/update) ─────────────────────────────────
Route::prefix('object-master')->group(function () {
    Route::post('create', [ObjectMasterController::class, 'create']);
    Route::post('getall', [ObjectMasterController::class, 'findAll']);
    Route::get('getby-id/{id}', [ObjectMasterController::class, 'findOne']);
    Route::patch('update/{id}', [ObjectMasterController::class, 'update']);
    Route::delete('delete/{id}', [ObjectMasterController::class, 'remove']);
});

// ─── ultra-object-master (multipart create/update) ───────────────────────────
Route::prefix('ultra-object-master')->group(function () {
    Route::post('create', [UltraObjectMasterController::class, 'create']);
    Route::post('getall', [UltraObjectMasterController::class, 'findAll']);
    Route::get('getby-id/{id}', [UltraObjectMasterController::class, 'findOne']);
    Route::patch('update/{id}', [UltraObjectMasterController::class, 'update']);
    Route::delete('delete/{id}', [UltraObjectMasterController::class, 'remove']);
});

// ─── reports ─────────────────────────────────────────────────────────────────
Route::prefix('reports')->group(function () {
    Route::post('kiosk-reports', [ReportsController::class, 'kioskReport']);
    Route::post('photographer-reports', [ReportsController::class, 'photographerReport']);
    Route::post('overall-reports', [ReportsController::class, 'overallReport']);
    Route::post('staff-reports', [ReportsController::class, 'staffReport']);
    Route::post('get-cash-orders', [ReportsController::class, 'getCashOrders']);
    Route::post('get-deleted-orders', [ReportsController::class, 'getDeletedOrders']);
    Route::delete('delete-orders/{order_id}', [ReportsController::class, 'deleteOrders']);
});

// ─── commission ──────────────────────────────────────────────────────────────
Route::prefix('commission')->group(function () {
    Route::post('get-commissions', [CommissionController::class, 'getCommissions']);
    Route::post('payout', [CommissionController::class, 'payout']);
    Route::post('get-payouts', [CommissionController::class, 'getPayouts']);
});

/*
 * ─── inventory ───────────────────────────────────────────────────────────────
 * New functionality — Product (no category), Unit Measurement, Warehouse,
 * Stock Movement, Stock In, Stock Out. Not a port of graspcraft_backend
 * (which has no inventory module); follows the create/getall/getby-id/
 * update/delete convention used by the newest ported masters (object-master,
 * ultra-object-master) rather than any Node original.
 */
Route::prefix('inventory')->group(function () {
    Route::prefix('product')->group(function () {
        Route::post('create', [InventoryProductController::class, 'create']);
        Route::post('getall', [InventoryProductController::class, 'findAll']);
        Route::get('getby-id/{id}', [InventoryProductController::class, 'findOne']);
        Route::patch('update/{id}', [InventoryProductController::class, 'update']);
        Route::delete('delete/{id}', [InventoryProductController::class, 'remove']);
        Route::get('{id}/uom-family', [InventoryProductController::class, 'uomFamily']);
    });

    Route::prefix('uom')->group(function () {
        Route::post('create', [UomController::class, 'create']);
        Route::post('getall', [UomController::class, 'findAll']);
        Route::get('base-units', [UomController::class, 'baseUnits']);
        Route::get('getby-id/{id}', [UomController::class, 'findOne']);
        Route::patch('update/{id}', [UomController::class, 'update']);
        Route::delete('delete/{id}', [UomController::class, 'remove']);
    });

    Route::prefix('warehouse')->group(function () {
        Route::post('create', [WarehouseController::class, 'create']);
        Route::post('getall', [WarehouseController::class, 'findAll']);
        Route::get('getby-id/{id}', [WarehouseController::class, 'findOne']);
        Route::patch('update/{id}', [WarehouseController::class, 'update']);
        Route::delete('delete/{id}', [WarehouseController::class, 'remove']);
    });

    // Read-only ledger, shared by the Stock In/Out screens for their on-hand lookup.
    Route::prefix('stock-movement')->group(function () {
        Route::post('getall', [StockMovementController::class, 'findAll']);
        Route::get('item-info', [StockMovementController::class, 'itemInfo']);
        Route::post('warehouse-stock', [StockMovementController::class, 'warehouseStock']);
        Route::get('getby-id/{id}', [StockMovementController::class, 'findOne']);
    });

    Route::prefix('stock-in')->group(function () {
        Route::post('create', [StockInController::class, 'create']);
    });

    Route::prefix('stock-out')->group(function () {
        Route::post('create', [StockOutController::class, 'create']);
    });

    // Moves stock for the same item between two warehouses — two linked rows
    // in the same stock_movements ledger, not a dedicated table.
    Route::prefix('stock-transfer')->group(function () {
        Route::post('create', [StockTransferController::class, 'create']);
    });

    /*
     * Turns a customer's order into real stock deductions, manually,
     * on-demand, from a back-office screen. Orders/checkout stay unaffected —
     * captureOrder() (called from UsersService::createOrder) only snapshots
     * cart contents that would otherwise be lost once checkout clears them.
     */
    Route::prefix('stock-migration')->group(function () {
        Route::post('getall', [StockMigrationController::class, 'findAll']);
        Route::get('getby-id/{orderId}', [StockMigrationController::class, 'findOne']);
        Route::post('migrate', [StockMigrationController::class, 'migrate']);
    });
});
