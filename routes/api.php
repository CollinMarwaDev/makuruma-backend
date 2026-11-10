<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProfileController;

use App\Http\Controllers\Api\TruckController;
use App\Http\Controllers\Api\TruckDocumentController;
use App\Http\Controllers\Api\DocumentController;

use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StaffDocumentController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\DriverDocumentController;

use App\Http\Controllers\Api\TrailerController;
use App\Http\Controllers\Api\TrailerDocumentController;

use App\Http\Controllers\Api\OfficeAssetController;

use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\VendorPaymentController;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BookingTruckDocumentController;
use App\Http\Controllers\Api\TripController;

use App\Http\Controllers\Api\CheckpointController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Controllers\Api\TrackingStatusController;
use App\Http\Controllers\Api\NotificationController;

use App\Http\Controllers\Api\ExpenseOrderController;
use App\Http\Controllers\Api\InvoiceController;

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TruckProfitReportController;
use App\Http\Controllers\Api\ReconciliationController;

// Public routes — no login required
Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

// Protected routes — must be logged in (valid token required)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::get('roles', [RoleController::class, 'index']);
    Route::apiResource('users', UserController::class);
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);

    Route::put('profile/password', [ProfileController::class, 'updatePassword']);

    Route::apiResource('trucks', TruckController::class);
    Route::get('trucks/{truck}/documents', [TruckDocumentController::class, 'index']);
    Route::post('trucks/{truck}/documents', [TruckDocumentController::class, 'store']);
    Route::apiResource('documents', DocumentController::class)->only(['show', 'update', 'destroy']);

    Route::apiResource('staff', StaffController::class);
    Route::get('staff/{staff}/documents', [StaffDocumentController::class, 'index']);
    Route::post('staff/{staff}/documents', [StaffDocumentController::class, 'store']);

    Route::apiResource('drivers', DriverController::class);
    Route::get('drivers/{driver}/documents', [DriverDocumentController::class, 'index']);
    Route::post('drivers/{driver}/documents', [DriverDocumentController::class, 'store']);

    Route::apiResource('trailers', TrailerController::class);
    Route::get('trailers/{trailer}/documents', [TrailerDocumentController::class, 'index']);
    Route::post('trailers/{trailer}/documents', [TrailerDocumentController::class, 'store']);

    Route::apiResource('office-assets', OfficeAssetController::class);

    Route::apiResource('clients', ClientController::class);
    Route::apiResource('vendors', VendorController::class);
    Route::apiResource('vendor-payments', VendorPaymentController::class)->only(['store', 'destroy']);

    Route::get('bookings/eligible-trucks', [BookingController::class, 'eligibleTrucks']);
    Route::apiResource('bookings', BookingController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::get('bookings/{booking}/download', [BookingController::class, 'download']);
    Route::post('bookings/{booking}/trucks', [BookingController::class, 'addTruck']);
    Route::put('bookings/{booking}/trucks/{bookingTruck}', [BookingController::class, 'swapTruck']);
    Route::delete('bookings/{booking}/trucks/{bookingTruck}', [BookingController::class, 'removeTruck']);

    Route::apiResource('trips', TripController::class)->only(['index', 'show']);
    Route::get('trucks/{truck}/profit-report', [TruckProfitReportController::class, 'downloadExcel']);

    Route::apiResource('checkpoints', CheckpointController::class)->only(['index', 'store']);

    // Tracking is per booking leg (Go / Return), contract 2.2 D-1.
    Route::get('tracking/export-excel', [TrackingController::class, 'downloadExcel']);
    Route::get('tracking', [TrackingController::class, 'index']);
    Route::get('tracking/{bookingTruck}', [TrackingController::class, 'show']);
    Route::put('tracking/{bookingTruck}/status', [TrackingController::class, 'updateStatus']);
    Route::post('tracking/{bookingTruck}/milestones', [TrackingController::class, 'upsertMilestone']);
    Route::get('tracking/{bookingTruck}/download', [TrackingController::class, 'download']);
    Route::put('booking-trucks/{bookingTruck}/dates', [TrackingController::class, 'updateTripDates']);
    Route::delete('booking-trucks/{bookingTruck}/documents/{document}', [BookingTruckDocumentController::class, 'destroy']);
    Route::get('documents/{document}/file', [DocumentController::class, 'file']);

    Route::apiResource('tracking-statuses', TrackingStatusController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::get('booking-trucks/{bookingTruck}/documents', [BookingTruckDocumentController::class, 'index']);
    Route::post('booking-trucks/{bookingTruck}/documents', [BookingTruckDocumentController::class, 'store']);

    Route::get('expense-orders/{expenseOrder}/download', [ExpenseOrderController::class, 'download']);
    Route::get('expense-orders/{expenseOrder}/download-excel', [ExpenseOrderController::class, 'downloadExcel']);
    Route::get('expense-orders/{expenseOrder}/download-category/{category}', [ExpenseOrderController::class, 'downloadCategory']);
    Route::apiResource('expense-orders', ExpenseOrderController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('expense-orders/{expenseOrder}/approve', [ExpenseOrderController::class, 'approve']);
    Route::post('expense-orders/{expenseOrder}/reject', [ExpenseOrderController::class, 'reject']);
    Route::post('expense-orders/{expenseOrder}/mark-paid', [ExpenseOrderController::class, 'markPaid']);

    Route::get('invoices/eligible-trucks', [InvoiceController::class, 'eligibleTrucks']);
    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::get('invoices/{invoice}/download', [InvoiceController::class, 'download']);
    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid']);

    Route::get('reconciliation/clients/{client}/summary', [ReconciliationController::class, 'clientSummary']);
    Route::get('reconciliation/clients/{client}/statement', [ReconciliationController::class, 'downloadClientStatement']);
    Route::get('reconciliation/vendors/{vendor}/summary', [ReconciliationController::class, 'vendorSummary']);
    Route::get('reconciliation/vendors/{vendor}/ledger', [ReconciliationController::class, 'downloadVendorLedger']);

    Route::get('dashboard/summary', [DashboardController::class, 'summary']);
});