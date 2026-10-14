<?php

use App\Http\Controllers\API\Admin\FeedbackController as AdminFeedbackController;
use App\Http\Controllers\API\Admin\OwnerReportController as AdminOwnerReportController;
use App\Http\Controllers\API\Admin\UserController as AdminUserController;
use App\Http\Controllers\API\Admin\VerificationController as AdminVerificationController;
use App\Http\Controllers\API\AssignmentController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\BrandingController;
use App\Http\Controllers\API\ContractController;
use App\Http\Controllers\API\ContractSignController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\FeedbackController;
use App\Http\Controllers\API\HouseController;
use App\Http\Controllers\API\ListingController;
use App\Http\Controllers\API\MediaController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\OwnerReportController;
use App\Http\Controllers\API\OwnerVerificationController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\ReceiptController;
use App\Http\Controllers\API\RentalController;
use App\Http\Controllers\API\RentalRequestController;
use App\Http\Controllers\API\RentChargeController;
use App\Http\Controllers\API\ReportController;
use App\Http\Controllers\API\RoomController;
use App\Http\Controllers\API\SearchController;
use App\Http\Controllers\API\TenantContractController;
use App\Http\Controllers\API\TenantController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');

// Public marketplace: active houses and their available rooms, no login.
Route::middleware('throttle:60,1')->prefix('listings')->group(function () {
    Route::get('/houses', [ListingController::class, 'houses']);
    Route::get('/houses/{house}', [ListingController::class, 'house']);
});

// Tenant signing. No login: the tenant receives a single link and must be able
// to sign without an account. The token is the credential, so every endpoint
// here is rate limited and the token is destroyed once spent.
Route::middleware('throttle:20,1')->prefix('contracts/sign')->group(function () {
    Route::get('/{token}', [ContractSignController::class, 'show'])->where('token', '[A-Za-z0-9]{64}');
    Route::post('/{token}', [ContractSignController::class, 'sign'])->where('token', '[A-Za-z0-9]{64}');
    Route::post('/{token}/decline', [ContractSignController::class, 'decline'])->where('token', '[A-Za-z0-9]{64}');
});

// Owner verification: reachable by an owner who is NOT yet approved, so they can
// upload documents and see their status. Everything else under /owner requires
// owner.verified.
Route::middleware(['auth:sanctum', 'role:owner', 'owner.active', 'password.change'])
    ->prefix('owner/verification')
    ->group(function () {
        Route::get('/', [OwnerVerificationController::class, 'show']);
        Route::post('/documents', [OwnerVerificationController::class, 'storeDocument']);
        Route::delete('/documents/{document}', [OwnerVerificationController::class, 'destroyDocument']);
        Route::post('/submit', [OwnerVerificationController::class, 'submit']);
    });

Route::middleware(['auth:sanctum', 'role:owner', 'owner.active', 'owner.verified', 'password.change'])->prefix('owner')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'owner']);

    // Rental requests from self-registered tenants.
    Route::get('/rental-requests', [RentalRequestController::class, 'ownerIndex']);
    Route::get('/rental-requests/{rentalRequest}', [RentalRequestController::class, 'ownerShow']);
    Route::post('/rental-requests/{rentalRequest}/accept', [RentalRequestController::class, 'accept']);
    Route::post('/rental-requests/{rentalRequest}/decline', [RentalRequestController::class, 'decline']);

    Route::apiResource('houses', HouseController::class);
    Route::get('/houses/{house}/rooms', [HouseController::class, 'rooms']);
    Route::post('/houses/{house}/rooms', [RoomController::class, 'store']);
    Route::get('/houses/{house}/summary', [HouseController::class, 'summary']);

    // Listing media: images and video for houses/rooms. Uploads land on the
    // public disk; videos may also be an external embed link.
    Route::post('/houses/{house}/media', [MediaController::class, 'storeForHouse']);
    Route::post('/rooms/{room}/media', [MediaController::class, 'storeForRoom']);
    Route::put('/media/{media}', [MediaController::class, 'update']);
    Route::delete('/media/{media}', [MediaController::class, 'destroy']);

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

// System administrator. User management, suspension and admin-role control;
// owner/tenant accounts are provisioned normally and are never self-assigned.
Route::middleware(['auth:sanctum', 'role:admin', 'active', 'password.change'])->prefix('admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::get('/users/{user}', [AdminUserController::class, 'show']);
    Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend']);
    Route::post('/users/{user}/activate', [AdminUserController::class, 'activate']);
    Route::post('/users/{user}/grant-admin', [AdminUserController::class, 'grantAdmin']);
    Route::post('/users/{user}/revoke-admin', [AdminUserController::class, 'revokeAdmin']);
    Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword']);

    // Owner verification queue: review uploaded identity/ownership documents
    // and approve or reject the account.
    Route::get('/owner-verifications', [AdminVerificationController::class, 'index']);
    Route::post('/users/{user}/approve', [AdminVerificationController::class, 'approve']);
    Route::post('/users/{user}/reject', [AdminVerificationController::class, 'reject']);
    Route::get('/verification-documents/{document}', [AdminVerificationController::class, 'streamDocument']);

    // Owner reports: tenants flag misbehaving owners; admins moderate them and
    // can suspend the owner from the same view. The reported owner is never
    // notified that a report exists.
    Route::get('/owner-reports', [AdminOwnerReportController::class, 'index']);
    Route::get('/owner-reports/{ownerReport}', [AdminOwnerReportController::class, 'show']);
    Route::patch('/owner-reports/{ownerReport}', [AdminOwnerReportController::class, 'update']);

    // Platform feedback from owners and tenants.
    Route::get('/feedback', [AdminFeedbackController::class, 'index']);
    Route::get('/feedback/{feedback}', [AdminFeedbackController::class, 'show']);
    Route::patch('/feedback/{feedback}', [AdminFeedbackController::class, 'update']);
});

Route::middleware(['auth:sanctum', 'active', 'password.change'])->group(function () {
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

    // Product feedback from any signed-in owner or tenant.
    Route::get('/feedback', [FeedbackController::class, 'index']);
    Route::post('/feedback', [FeedbackController::class, 'store']);

    // The tenant's own view of their contracts, backing "My Contracts". Scoped
    // by the signed-in tenant, so no tenant can read another's tenancy terms.
    Route::middleware('role:tenant')->group(function () {
        Route::get('/rental-requests', [RentalRequestController::class, 'tenantIndex']);
        Route::post('/rental-requests', [RentalRequestController::class, 'store']);

        // Reporting an owner the tenant has a standing relationship with.
        Route::get('/owner-reports/eligible-owners', [OwnerReportController::class, 'eligibleOwners']);
        Route::get('/owner-reports', [OwnerReportController::class, 'index']);
        Route::post('/owner-reports', [OwnerReportController::class, 'store']);
        Route::post('/owner-reports/{ownerReport}/evidence', [OwnerReportController::class, 'addEvidence']);

        // First-time tenants confirm they verified a reserved room is genuine.
        // Informational only — it never blocks signing.
        Route::post('/rentals/{rental}/confirm-proceed', [RentalController::class, 'confirmProceed']);

        Route::get('/contracts', [TenantContractController::class, 'index']);
        Route::get('/contracts/{id}', [TenantContractController::class, 'show'])->where('id', '[0-9]+');
    });
});

// Branding
Route::get('/branding', [BrandingController::class, 'index']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/branding/me', [BrandingController::class, 'me']);
    Route::post('/branding/logo', [BrandingController::class, 'uploadLogo']);
    Route::delete('/branding/logo', [BrandingController::class, 'destroyLogo']);
    Route::post('/branding/signature', [BrandingController::class, 'uploadSignature']);
    Route::delete('/branding/signature', [BrandingController::class, 'destroySignature']);
});
