<?php

use App\Workforce\Http\AdvanceController;
use App\Workforce\Http\AttendanceController;
use App\Workforce\Http\EmployeeController;
use App\Workforce\Http\FoundationController;
use App\Workforce\Http\LeaveController;
use App\Workforce\Http\OverviewController;
use App\Workforce\Http\PayrollController;
use App\Workforce\Http\RequireFreshPassword;
use App\Workforce\Http\RosterController;
use App\Workforce\Http\SalaryController;
use App\Workforce\Http\SetupController;
use App\Workforce\Http\StaffCheckController;
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
    Route::get('/me/today', 'today');
    Route::post('/me/leave', 'askLeave');
    Route::post('/me/leave/{leave}/cancel', 'cancelLeave');
    Route::post('/me/corrections', 'askCorrection');
    Route::post('/me/punch', 'punch');
    Route::get('/me/payslips/{slip}', 'myPayslip')->whereNumber('slip');
});

// Where logins and staff records do not yet agree, and putting that right.
Route::controller(StaffCheckController::class)->prefix('staff-check')->group(function () {
    Route::get('/', 'show');
    Route::post('/join', 'join');
    Route::post('/create', 'create');
    Route::post('/settle', 'settle');
    Route::post('/unjoin/{employee}', 'unjoin')->whereNumber('employee');
    Route::post('/shared-screen/{user}', 'sharedScreen')->whereNumber('user');
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

// The duty roster: who is on which duty on which day.
Route::controller(RosterController::class)->prefix('attendance/roster')->group(function () {
    Route::get('/', 'show');
    Route::put('/', 'save');
    Route::post('/copy', 'copy');
});

Route::controller(AttendanceController::class)->prefix('attendance')->group(function () {
    Route::get('/day', 'day');
    Route::get('/month', 'month');
    Route::get('/export', 'export');
    Route::get('/times', 'inAndOut');
    Route::get('/times/export', 'exportInAndOut');
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

// What salaries are made of, and what each person is paid.
Route::controller(SalaryController::class)->prefix('pay')->group(function () {
    Route::get('/components', 'components');
    Route::post('/components/starter', 'starter');
    Route::post('/components', 'saveComponent');
    Route::put('/components/{component}', 'saveComponent')->whereNumber('component');
    Route::get('/salaries', 'index');
    Route::get('/salaries/{employee}', 'show')->whereNumber('employee');
    Route::post('/salaries/{employee}', 'store')->whereNumber('employee');
    Route::post('/structures/{structure}/approve', 'approve')->whereNumber('structure');
    Route::post('/structures/{structure}/withdraw', 'withdraw')->whereNumber('structure');
});

// Payroll runs. Finalising, reversing and the register export need the password typed again.
Route::controller(PayrollController::class)->prefix('payroll')->group(function () {
    Route::get('/runs', 'index');
    Route::post('/runs', 'store');
    Route::get('/outstanding', 'outstanding');
    Route::get('/payslips/{slip}', 'payslip')->whereNumber('slip');
    Route::get('/runs/{run}', 'show')->whereNumber('run');
    Route::post('/runs/{run}/work-out', 'workOut')->whereNumber('run');
    Route::post('/runs/{run}/hold-back', 'holdBack')->whereNumber('run');
    Route::post('/runs/{run}/payslips/{slip}/lines', 'addLine')->whereNumber(['run', 'slip']);
    Route::delete('/runs/{run}/payslips/{slip}/lines/{line}', 'removeLine')->whereNumber(['run', 'slip', 'line']);
    Route::post('/runs/{run}/step', 'step')->whereNumber('run');
    Route::post('/runs/{run}/pay', 'pay')->whereNumber('run');
    Route::middleware(RequireFreshPassword::class)->group(function () {
        Route::post('/runs/{run}/finalise', 'finalise')->whereNumber('run');
        Route::post('/runs/{run}/reverse', 'reverse')->whereNumber('run');
        Route::get('/runs/{run}/export', 'export')->whereNumber('run');
    });
});

// Advances and loans. Letting go of what is owed, and the export, need the password typed again.
Route::controller(AdvanceController::class)->prefix('advances')->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/export', 'export')->middleware(RequireFreshPassword::class);
    Route::get('/{advance}', 'show')->whereNumber('advance');
    Route::post('/{advance}/decide', 'decide')->whereNumber('advance');
    Route::post('/{advance}/issue', 'issue')->whereNumber('advance');
    Route::post('/{advance}/repay', 'repay')->whereNumber('advance');
    Route::post('/{advance}/skip', 'skip')->whereNumber('advance');
    Route::post('/{advance}/let-go', 'letGo')->whereNumber('advance')->middleware(RequireFreshPassword::class);
});
