<?php

use App\Workforce\Http\AttendanceController;
use App\Workforce\Http\EmployeeController;
use App\Workforce\Http\FoundationController;
use App\Workforce\Http\LeaveController;
use App\Workforce\Http\OverviewController;
use App\Workforce\Http\SetupController;
use Illuminate\Support\Facades\Route;

/*
 * The workforce module: staff records, attendance, leave, payroll, advances and expenses.
 * Loaded from routes/api.php under /api/workforce, for signed-in staff only. Each route checks its own
 * permission (App\Workforce\Support\Permissions) and reach (App\Workforce\Support\Access).
 */
Route::controller(FoundationController::class)->group(function () {
    Route::get('/access', 'access');
    Route::get('/permission-matrix', 'matrix');
    Route::post('/confirm-password', 'confirmPassword')->middleware('throttle:6,1');
    Route::get('/audit', 'audit');
    Route::get('/audit/verify', 'verifyAudit');
});

Route::controller(OverviewController::class)->group(function () {
    Route::get('/dashboard', 'dashboard');
    Route::get('/me', 'me');
    Route::post('/me/leave', 'askLeave');
    Route::post('/me/leave/{leave}/cancel', 'cancelLeave');
    Route::post('/me/corrections', 'askCorrection');
    Route::post('/me/punch', 'punch');
});

Route::controller(EmployeeController::class)->group(function () {
    Route::get('/options', 'options');
    Route::get('/employees', 'index');
    Route::get('/employees/export', 'export');
    Route::post('/employees', 'store');
    Route::get('/employees/{employee}', 'show');
    Route::put('/employees/{employee}', 'update');
    Route::get('/employees/{employee}/private', 'private');
    Route::put('/employees/{employee}/private', 'savePrivate');
    Route::match(['get', 'put'], '/departments/{department}/managers', 'managers');
});

Route::controller(SetupController::class)->prefix('setup')->group(function () {
    Route::get('/', 'show');
    Route::put('/settings', 'saveSettings');
    Route::post('/shifts', 'saveShift');
    Route::put('/shifts/{shift}', 'saveShift');
    Route::post('/calendars', 'saveCalendar');
    Route::put('/calendars/{calendar}', 'saveCalendar');
    Route::post('/calendars/{calendar}/holidays', 'saveHoliday');
    Route::put('/calendars/{calendar}/holidays/{holiday}', 'saveHoliday');
    Route::delete('/calendars/{calendar}/holidays/{holiday}', 'removeHoliday');
    Route::post('/leave-types', 'saveLeaveType');
    Route::put('/leave-types/{type}', 'saveLeaveType');
});

Route::controller(AttendanceController::class)->prefix('attendance')->group(function () {
    Route::get('/day', 'day');
    Route::get('/month', 'month');
    Route::get('/export', 'export');
    Route::post('/check-in', 'checkIn');
    Route::post('/', 'store');
    Route::get('/corrections', 'corrections');
    Route::post('/corrections', 'requestCorrection');
    Route::post('/corrections/{correction}/decide', 'decideCorrection');
    Route::post('/imports', 'previewImport');
    Route::get('/imports/{import}', 'showImport');
    Route::post('/imports/{import}/apply', 'applyImport');
    Route::put('/{attendance}', 'update')->whereNumber('attendance');
    Route::post('/{attendance}/check-out', 'checkOut')->whereNumber('attendance');
});

Route::controller(LeaveController::class)->prefix('leave')->group(function () {
    Route::get('/', 'index');
    Route::get('/types', 'types');
    Route::get('/balances', 'balances');
    Route::get('/export', 'export');
    Route::post('/', 'store');
    Route::post('/adjust', 'adjust');
    Route::post('/housekeeping', 'housekeeping');
    Route::get('/ledger/{employee}', 'ledger');
    Route::post('/{leave}/decide', 'decide')->whereNumber('leave');
    Route::post('/{leave}/cancel', 'cancel')->whereNumber('leave');
});
