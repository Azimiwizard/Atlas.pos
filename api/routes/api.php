<?php

use App\Domain\Finance\Http\Controllers\ExpenseController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Backoffice\AnalyticsController;
use App\Http\Controllers\Backoffice\FinanceController;
use App\Http\Controllers\Backoffice\CategoryController as BackofficeCategoryController;
use App\Http\Controllers\Backoffice\MenuCategoryController as BackofficeMenuCategoryController;
use App\Http\Controllers\Backoffice\OptionController as BackofficeOptionController;
use App\Http\Controllers\Backoffice\OptionGroupController as BackofficeOptionGroupController;
use App\Http\Controllers\Backoffice\ProductController as BackofficeProductController;
use App\Http\Controllers\Backoffice\StockController as BackofficeStockController;
use App\Http\Controllers\Backoffice\UploadController as BackofficeUploadController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\PublicTenantController;
use App\Http\Controllers\PosMenuController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\VariantController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $singleTenant = (bool) config('tenancy.single_tenant');

    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'singleTenant' => $singleTenant,
        'tenantSlug' => $singleTenant ? (string) config('tenancy.default_tenant_slug', 'default') : null,
    ]);
});

Route::get('/public/tenants/presets', [PublicTenantController::class, 'presets']);

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::prefix('bo')->middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::middleware('role:admin,manager')->group(function () {
        Route::apiResource('menu/categories', BackofficeMenuCategoryController::class)
            ->parameters(['categories' => 'menu_category']);
        Route::patch('menu/categories/{menu_category}/toggle', [BackofficeMenuCategoryController::class, 'toggle']);

        Route::apiResource('products', BackofficeProductController::class);
        Route::apiResource('products.option-groups', BackofficeOptionGroupController::class)
            ->parameters(['option-groups' => 'option_group']);
        Route::apiResource('products.option-groups.options', BackofficeOptionController::class)
            ->parameters(['options' => 'option']);

        Route::post('/uploads/images', [BackofficeUploadController::class, 'store']);
        Route::get('/stocks', [BackofficeStockController::class, 'index']);
        Route::post('/stocks/adjust', [BackofficeStockController::class, 'adjust']);
        Route::apiResource('expenses', ExpenseController::class);
    });

    Route::get('/categories', [BackofficeCategoryController::class, 'index'])->middleware('role:admin,manager');

    Route::prefix('analytics')->group(function () {
        Route::middleware('role:admin,manager,cashier')->group(function () {
            Route::get('/summary', [AnalyticsController::class, 'summary']);
            Route::get('/hourly-heatmap', [AnalyticsController::class, 'hourlyHeatmap']);
            Route::get('/refunds', [AnalyticsController::class, 'refunds']);
            Route::get('/export.csv', [AnalyticsController::class, 'export']);
        });

        Route::get('/cashiers', [AnalyticsController::class, 'cashiers'])
            ->middleware('role:admin,manager');
    });

    Route::prefix('finance')->middleware('role:admin,manager')->group(function () {
        Route::get('/summary', [FinanceController::class, 'summary']);
        Route::get('/flow', [FinanceController::class, 'flow']);
        Route::get('/expenses', [FinanceController::class, 'expenses']);
        Route::get('/health', [FinanceController::class, 'health']);
        Route::get('/meta', [FinanceController::class, 'meta']);
        Route::get('/export.csv', [FinanceController::class, 'exportCsv']);
        Route::get('/export.pdf', [FinanceController::class, 'exportPdf']);
        Route::get('/export/status/{finance_export}', [FinanceController::class, 'exportStatus'])
            ->name('finance.export.status');
    });
});

Route::get('/bo/finance/export/download/{finance_export}', [FinanceController::class, 'downloadExport'])
    ->name('finance.export.download')
    ->middleware('signed');

Route::middleware(['tenant', 'auth:sanctum', 'store'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/me/password', [UsersController::class, 'changeMyPassword']);

    // Read-any
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

    // Writes for products/variants, categories/taxes/promotions, registers, shifts open/close
    Route::middleware('role:admin,manager')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        Route::post('products/{product}/variants', [VariantController::class, 'store']);
        Route::put('products/{product}/variants/{variant}', [VariantController::class, 'update']);
        Route::patch('products/{product}/variants/{variant}', [VariantController::class, 'update']);
        Route::delete('products/{product}/variants/{variant}', [VariantController::class, 'destroy']);

        Route::post('/products/{product}/categories', [ProductController::class, 'syncCategories']);
        Route::post('/products/{product}/taxes', [ProductController::class, 'syncTaxes']);

        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);

        Route::post('/taxes', [TaxController::class, 'store']);
        Route::put('/taxes/{tax}', [TaxController::class, 'update']);

        Route::post('/promotions', [PromotionController::class, 'store']);
        Route::put('/promotions/{promotion}', [PromotionController::class, 'update']);

        Route::post('/registers', [RegisterController::class, 'store']);
        Route::put('/registers/{register}', [RegisterController::class, 'update']);

        Route::post('/shifts/open', [ShiftController::class, 'open']);
        Route::post('/shifts/{shift}/close', [ShiftController::class, 'close']);

        Route::get('/stores', [StoreController::class, 'index']);
        Route::post('/stores', [StoreController::class, 'store']);
        Route::put('/stores/{store}', [StoreController::class, 'update']);
        Route::delete('/stores/{store}', [StoreController::class, 'destroy']);
    });

    Route::get('products/{product}/variants', [VariantController::class, 'index']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'createDraft'])->middleware('role:admin,manager,cashier');
    Route::post('/orders/{order}/items', [OrderController::class, 'addItem'])->middleware('role:admin,manager,cashier');
    Route::post('/orders/{order}/checkout', [OrderController::class, 'checkout'])->middleware('role:admin,manager,cashier');
    Route::post('/orders/{order}/capture', [OrderController::class, 'capture'])->middleware('role:admin,manager,cashier');
    Route::post('/orders/{order}/customer', [OrderController::class, 'attachCustomer']);
    Route::post('/orders/{order}/discount', [OrderController::class, 'applyDiscount']);
    Route::post('/orders/{order}/attach-shift', [OrderController::class, 'attachShift']);
    Route::post('/orders/{order}/refund', [OrderController::class, 'refund'])->middleware('role:admin,manager');
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::get('/orders/{id}/receipt', [OrderController::class, 'receipt']);

    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('role:admin,manager,cashier');
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);

    Route::get('/categories', [CategoryController::class, 'index']);

    // writes moved to admin/manager group

    Route::get('/taxes', [TaxController::class, 'index']);

    Route::get('/promotions', [PromotionController::class, 'index']);
    Route::get('/pos/menu', [PosMenuController::class, 'index']);

    Route::get('/registers', [RegisterController::class, 'index']);

    Route::get('/shifts', [ShiftController::class, 'index']);
    Route::post('/shifts/{shift}/cash', [ShiftController::class, 'cashMovement'])->middleware('role:admin,manager,cashier');
    Route::get('/shifts/current', [ShiftController::class, 'current']);
    Route::get('/shifts/{shift}', [ShiftController::class, 'show']);

    // Reports (admin/manager)
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/by-day', [ReportController::class, 'byDay']);
        Route::get('/reports/top-products', [ReportController::class, 'topProducts']);
        Route::get('/reports/top-customers', [ReportController::class, 'topCustomers']);
        Route::get('/reports/payment-mix', [ReportController::class, 'paymentMix']);
        Route::get('/reports/tax-breakdown', [ReportController::class, 'taxBreakdown']);
        Route::get('/reports/export/csv', [ReportController::class, 'exportCsv']);
    });
    // Admin-only user management
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UsersController::class, 'index']);
        Route::post('/users', [UsersController::class, 'store']);
        Route::get('/users/{user}', [UsersController::class, 'show']);
        Route::put('/users/{user}', [UsersController::class, 'update']);
        Route::delete('/users/{user}', [UsersController::class, 'destroy']);
        Route::post('/users/{user}/reset-password', [UsersController::class, 'resetPassword']);
    });
});





