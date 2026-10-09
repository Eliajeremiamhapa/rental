<?php

use App\Http\Controllers\API\AssignmentController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ContractController;
use App\Http\Controllers\API\ContractSignController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\HouseController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\ReceiptController;
use App\Http\Controllers\API\RentalController;
use App\Http\Controllers\API\RentChargeController;
use App\Http\Controllers\API\ReportController;
use App\Http\Controllers\API\RoomController;
use App\Http\Controllers\API\SearchController;
use App\Http\Controllers\API\TenantContractController;
use App\Http\Controllers\API\TenantController;
use Illuminate\Support\Facades\Route;

// Accounts are owner-managed: owners are provisioned by the seeder/administrator
// and tenants are created by their owner, so there is no public sign-up.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');

// Tenant signing. No login: the tenant receives a single link and must be able
// to sign without an account. The token is the credential, so every endpoint
// here is rate limited and the token is destroyed once spent.
Route::middleware('throttle:20,1')->prefix('contracts/sign')->group(function () {
    Route::get('/{token}', [ContractSignController::class, 'show'])->where('token', '[A-Za-z0-9]{64}');
    Route::post('/{token}', [ContractSignController::class, 'sign'])->where('token', '[A-Za-z0-9]{64}');
    Route::post('/{token}/decline', [ContractSignController::class, 'decline'])->where('token', '[A-Za-z0-9]{64}');
});

Route::middleware(['auth:sanctum', 'role:owner', 'owner.active', 'password.change'])->prefix('owner')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'owner']);

    Route::apiResource('houses', HouseController::class);
    Route::get('/houses/{house}/rooms', [HouseController::class, 'rooms']);
    Route::post('/houses/{house}/rooms', [RoomController::class, 'store']);
    Route::get('/houses/{house}/summary', [HouseController::class, 'summary']);

    Route::apiResource('tenants', TenantController::class);
    Route::post('/tenants/{tenant}/reset-password', [TenantController::class, 'resetPassword']);
    Route::get('/tenants/{tenant}/rentals', [TenantController::class, 'rentals']);
    Route::get('/tenants/{tenant}/payments', [TenantController::class, 'payments']);
    Route::get('/tenants/{tenant}/balance', [TenantController::class, 'balance']);

    Route::post('/assignments', [AssignmentController::class, 'store']);
    Route::post('/assignments/{id}/end', [AssignmentController::class, 'endTenancy']);
    Route::get('/assignments/history', [AssignmentController::class, 'history']);

    // Contract building and single-use tenant signing.
    Route::get('/contracts', [ContractController::class, 'index']);
    Route::post('/contracts/preview', [ContractController::class, 'preview']);
    Route::post('/contracts', [ContractController::class, 'store']);
    Route::get('/contracts/settings', [ContractController::class, 'settings']);
    Route::put('/contracts/settings', [ContractController::class, 'updateSettings']);
    Route::get('/rentals/{rental}/contract-prefill', [ContractController::class, 'prefill']);
    Route::get('/contracts/{id}', [ContractController::class, 'show'])->where('id', '[0-9]+');
    Route::put('/contracts/{id}', [ContractController::class, 'update'])->where('id', '[0-9]+');
    Route::post('/contracts/{id}/send', [ContractController::class, 'send'])->where('id', '[0-9]+');
    Route::post('/contracts/{id}/void', [ContractController::class, 'void'])->where('id', '[0-9]+');

    Route::get('/reports/rent-collection', [ReportController::class, 'rentCollection']);
    Route::get('/reports/tenant-summary', [ReportController::class, 'tenantSummary']);
    Route::get('/reports/room-performance', [ReportController::class, 'roomPerformance']);
    Route::get('/reports/house-income', [ReportController::class, 'houseIncome']);
    Route::get('/reports/arrears', [ReportController::class, 'arrears']);
    Route::get('/reports/expiry', [ReportController::class, 'expiry']);
    Route::get('/reports/monthly', [ReportController::class, 'monthly']);

    Route::get('/search', [SearchController::class, 'index']);
    Route::get('/search/tenants', [SearchController::class, 'tenants']);
    Route::get('/search/rooms', [SearchController::class, 'rooms']);
    Route::get('/search/houses', [SearchController::class, 'houses']);
    Route::get('/search/payments', [SearchController::class, 'payments']);
});

Route::middleware(['auth:sanctum', 'password.change'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard/tenant', [DashboardController::class, 'tenant']);

    Route::apiResource('rooms', RoomController::class)->only(['index', 'show', 'update', 'destroy']);
    Route::get('/rooms/{room}/history', [RoomController::class, 'history']);

    Route::apiResource('rentals', RentalController::class);
    Route::post('/rentals/{rental}/renew', [RentalController::class, 'renew']);
    Route::post('/rentals/{rental}/terminate', [RentalController::class, 'terminate']);
    Route::get('/rentals/{rental}/charges', [RentalController::class, 'charges']);

    Route::get('/rent-charges', [RentChargeController::class, 'index']);
    // Literal paths must be declared before the {rentalCharge} parameter route,
    // otherwise they are swallowed by implicit route-model binding and 404.
    Route::get('/rent-charges/upcoming', [RentChargeController::class, 'upcoming']);
    Route::get('/rent-charges/overdue', [RentChargeController::class, 'overdue']);
    Route::get('/rent-charges/{rentalCharge}', [RentChargeController::class, 'show']);
    Route::post('/rent-charges/generate', [RentChargeController::class, 'generate']);

    Route::apiResource('payments', PaymentController::class)->only(['index', 'show']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/tenant/{tenantId}', [PaymentController::class, 'tenantHistory']);

    Route::get('/receipts', [ReceiptController::class, 'index']);
    Route::get('/receipts/{receipt}', [ReceiptController::class, 'show']);
    Route::get('/receipts/{receipt}/download', [ReceiptController::class, 'download']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    // The tenant's own view of their contracts, backing "My Contracts". Scoped
    // by the signed-in tenant, so no tenant can read another's tenancy terms.
    Route::middleware('role:tenant')->group(function () {
        Route::get('/contracts', [TenantContractController::class, 'index']);
        Route::get('/contracts/{id}', [TenantContractController::class, 'show'])->where('id', '[0-9]+');
    });
});
