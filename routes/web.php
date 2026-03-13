<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AttendanceLogController;
use App\Http\Controllers\BookingRuleController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\CrmAutomationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalonServiceController;
use App\Http\Controllers\StaffProfileController;
use App\Http\Controllers\StaffScheduleController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicBookingController::class, 'create'])->name('public.booking');
Route::post('/book', [PublicBookingController::class, 'store'])->name('public.booking.store');
Route::get('/portal/{token}', [CustomerPortalController::class, 'show'])->name('customer.portal.show');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::middleware('role:owner,manager,staff')->group(function () {
        Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::put('/appointments/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');
        Route::post('/appointments/{appointment}/service-start', [AppointmentController::class, 'startService'])->name('appointments.service-start');
        Route::post('/appointments/{appointment}/service-complete', [AppointmentController::class, 'completeService'])->name('appointments.service-complete');
        Route::patch('/appointments/{appointment}/transition', [AppointmentController::class, 'transition'])->name('appointments.transition');
        Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy'])->name('appointments.destroy');

        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::post('/customers/{customer}/portal-token', [CustomerController::class, 'issuePortalToken'])->name('customers.portal-token.store');

        Route::get('/attendance', [AttendanceLogController::class, 'index'])->name('attendance.index');
        Route::post('/attendance/clock-in', [AttendanceLogController::class, 'clockIn'])->name('attendance.clock-in');
        Route::post('/attendance/clock-out', [AttendanceLogController::class, 'clockOut'])->name('attendance.clock-out');

        Route::get('/leave-requests', [LeaveRequestController::class, 'index'])->name('leave-requests.index');
        Route::post('/leave-requests', [LeaveRequestController::class, 'store'])->name('leave-requests.store');

        Route::post('/feedback/staff-to-customer', [FeedbackController::class, 'storeStaffToCustomer'])->name('feedback.staff-to-customer.store');
    });

    Route::middleware('role:owner,manager,customer')->group(function () {
        Route::post('/feedback/customer-to-staff', [FeedbackController::class, 'storeCustomerToStaff'])->name('feedback.customer-to-staff.store');
    });

    Route::middleware('role:owner,manager')->group(function () {
        Route::get('/services', [SalonServiceController::class, 'index'])->name('services.index');
        Route::post('/services', [SalonServiceController::class, 'store'])->name('services.store');
        Route::put('/services/{service}', [SalonServiceController::class, 'update'])->name('services.update');
        Route::delete('/services/{service}', [SalonServiceController::class, 'destroy'])->name('services.destroy');

        Route::get('/staff', [StaffProfileController::class, 'index'])->name('staff.index');
        Route::post('/staff', [StaffProfileController::class, 'store'])->name('staff.store');
        Route::put('/staff/{staff}', [StaffProfileController::class, 'update'])->name('staff.update');
        Route::delete('/staff/{staff}', [StaffProfileController::class, 'destroy'])->name('staff.destroy');

        Route::get('/schedules', [StaffScheduleController::class, 'index'])->name('schedules.index');
        Route::post('/schedules', [StaffScheduleController::class, 'store'])->name('schedules.store');
        Route::put('/schedules/{schedule}', [StaffScheduleController::class, 'update'])->name('schedules.update');
        Route::delete('/schedules/{schedule}', [StaffScheduleController::class, 'destroy'])->name('schedules.destroy');
        Route::patch('/booking-rules', [BookingRuleController::class, 'update'])->name('booking-rules.update');

        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::post('/inventory', [InventoryController::class, 'store'])->name('inventory.store');
        Route::put('/inventory/{item}', [InventoryController::class, 'update'])->name('inventory.update');
        Route::post('/inventory/{item}/adjust', [InventoryController::class, 'adjustStock'])->name('inventory.adjust');
        Route::delete('/inventory/{item}', [InventoryController::class, 'destroy'])->name('inventory.destroy');
        Route::post('/inventory/alerts/scan', [InventoryController::class, 'scanAlerts'])->name('inventory.alerts.scan');
        Route::patch('/inventory/alerts/{alert}/resolve', [InventoryController::class, 'resolveAlert'])->name('inventory.alerts.resolve');

        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
        Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
        Route::patch('/purchase-orders/{purchaseOrder}/transition', [PurchaseOrderController::class, 'transition'])->name('purchase-orders.transition');

        Route::get('/loyalty', [LoyaltyController::class, 'index'])->name('loyalty.index');
        Route::post('/loyalty/tiers', [LoyaltyController::class, 'storeTier'])->name('loyalty.tiers.store');
        Route::put('/loyalty/tiers/{tier}', [LoyaltyController::class, 'updateTier'])->name('loyalty.tiers.update');
        Route::post('/loyalty/card-types', [LoyaltyController::class, 'storeCardType'])->name('loyalty.card-types.store');
        Route::put('/loyalty/card-types/{cardType}', [LoyaltyController::class, 'updateCardType'])->name('loyalty.card-types.update');
        Route::post('/loyalty/cards/assign', [LoyaltyController::class, 'assignCard'])->name('loyalty.cards.assign');
        Route::post('/loyalty/packages', [LoyaltyController::class, 'storePackage'])->name('loyalty.packages.store');
        Route::post('/loyalty/packages/assign', [LoyaltyController::class, 'assignPackage'])->name('loyalty.packages.assign');
        Route::post('/loyalty/packages/{customerPackage}/consume', [LoyaltyController::class, 'consumePackage'])->name('loyalty.packages.consume');
        Route::post('/loyalty/gift-cards', [LoyaltyController::class, 'issueGiftCard'])->name('loyalty.gift-cards.store');
        Route::post('/loyalty/gift-cards/{giftCard}/consume', [LoyaltyController::class, 'consumeGiftCard'])->name('loyalty.gift-cards.consume');
        Route::post('/loyalty/ledger', [LoyaltyController::class, 'storeLedger'])->name('loyalty.ledger.store');
        Route::post('/loyalty/rewards', [LoyaltyController::class, 'storeReward'])->name('loyalty.rewards.store');
        Route::put('/loyalty/rewards/{reward}', [LoyaltyController::class, 'updateReward'])->name('loyalty.rewards.update');
        Route::post('/loyalty/redeem', [LoyaltyController::class, 'redeem'])->name('loyalty.redeem');
        Route::patch('/loyalty/settings', [LoyaltyController::class, 'updateSettings'])->name('loyalty.settings.update');
        Route::post('/loyalty/bonus', [LoyaltyController::class, 'awardBonus'])->name('loyalty.bonus.award');

        Route::get('/customers/automation', [CrmAutomationController::class, 'index'])->name('customers.automation.index');
        Route::post('/customers/automation/tags', [CrmAutomationController::class, 'storeTag'])->name('customers.automation.tags.store');
        Route::post('/customers/automation/tags/assign', [CrmAutomationController::class, 'assignTag'])->name('customers.automation.tags.assign');
        Route::delete('/customers/automation/tags/remove', [CrmAutomationController::class, 'removeTag'])->name('customers.automation.tags.remove');
        Route::post('/customers/automation/segment-rules', [CrmAutomationController::class, 'storeSegmentRule'])->name('customers.automation.segment-rules.store');
        Route::put('/customers/automation/segment-rules/{rule}', [CrmAutomationController::class, 'updateSegmentRule'])->name('customers.automation.segment-rules.update');
        Route::patch('/customers/automation/segment-rules/{rule}/deactivate', [CrmAutomationController::class, 'deactivateSegmentRule'])->name('customers.automation.segment-rules.deactivate');
        Route::post('/customers/automation/segment-rules/{rule}/preview', [CrmAutomationController::class, 'previewSegmentRule'])->name('customers.automation.segment-rules.preview');
        Route::post('/customers/automation/segment-rules/run', [CrmAutomationController::class, 'runSegmentRules'])->name('customers.automation.segment-rules.run');
        Route::post('/customers/automation/due-services/generate', [CrmAutomationController::class, 'generateDueServices'])->name('customers.automation.due-services.generate');
        Route::post('/customers/automation/due-services/{dueService}/remind', [CrmAutomationController::class, 'sendReminder'])->name('customers.automation.due-services.remind');
        Route::patch('/customers/automation/due-services/{dueService}/status', [CrmAutomationController::class, 'updateDueStatus'])->name('customers.automation.due-services.status');
        Route::post('/customers/automation/campaign-templates', [CrmAutomationController::class, 'storeCampaignTemplate'])->name('customers.automation.campaign-templates.store');
        Route::put('/customers/automation/campaign-templates/{template}', [CrmAutomationController::class, 'updateCampaignTemplate'])->name('customers.automation.campaign-templates.update');
        Route::post('/customers/automation/campaigns', [CrmAutomationController::class, 'storeCampaign'])->name('customers.automation.campaigns.store');
        Route::post('/customers/automation/campaigns/{campaign}/dispatch', [CrmAutomationController::class, 'dispatchCampaign'])->name('customers.automation.campaigns.dispatch');
        Route::post('/customers/automation/campaigns/run-scheduled', [CrmAutomationController::class, 'runScheduledCampaigns'])->name('customers.automation.campaigns.run-scheduled');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/reports/export/pdf', [ReportController::class, 'exportPdf'])->name('reports.export.pdf');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

        Route::patch('/leave-requests/{leaveRequest}/review', [LeaveRequestController::class, 'review'])->name('leave-requests.review');
    });
});

require __DIR__.'/auth.php';
